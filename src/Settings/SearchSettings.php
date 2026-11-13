<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Search\Settings;

use Configuration;
use Quince\Engine\Settings\ConfigStore;

/**
 * Ustawienia wyszukiwarki.
 *
 * Trzy rzeczy: zakresy (w czym sklep pozwala szukać), podpowiedzi (co się dzieje
 * w trakcie pisania) i strona pustych wyników (co widzi klient, który nie znalazł).
 *
 * Zakresy i pusta strona idą jako wpisy JSON, nie jako osobne klucze konfiguracji.
 * Zakresów jest dowolnie wiele i mają wspólną strukturę.
 */
final class SearchSettings
{
    private const PREFIX = 'QUINCE_SEARCH_';

    /** Ile kafelków kategorii mieści się w jednym wierszu na stronie bez wyników. */
    public const CATS_PER_ROW_MIN = 2;
    public const CATS_PER_ROW_MAX = 8;
    public const CATS_PER_ROW_DEFAULT = 5;

    /**
     * Typy zakresów. Nazwa typu jest zaszyta w kodzie, bo do każdego przypisane
     * jest inne zapytanie do bazy. Sprzedawca decyduje, których użyć i jak je
     * nazwać, a nie skąd biorą dane.
     *
     * @var string[]
     */
    public const TYPES = ['all', 'name', 'reference', 'category', 'manufacturer', 'feature'];

    /** Typy wymagające wskazania konkretnego obiektu (cecha produktu). */
    public const TYPES_WITH_TARGET = ['feature'];

    /** @var array<string,string> */
    private const TYPE_LABELS = [
        'all' => 'Everywhere',
        'name' => 'In product name',
        'reference' => 'By code or SKU',
        'category' => 'In category',
        'manufacturer' => 'By brand',
        'feature' => 'By product feature',
    ];

    /** @var array<string,string> */
    private const SCHEMA = [
        'SCOPES' => '',
        'EMPTY' => '',
        'SUGGEST_ENABLED' => '1',
        'SUGGEST_MIN_CHARS' => '3',
        'SUGGEST_LIMIT' => '6',
    ];

    /** @return array<string,string> */
    public function all(): array
    {
        $out = [];

        // Wartość domyślna wchodzi wyłącznie wtedy, gdy klucza nie ma w konfiguracji.
        // Pusty napis jest wyborem administratora (tak zapisuje się „bez ikony"),
        // a podmiana go na wartość domyślną przywracałaby skasowaną ikonę przy odczycie.
        foreach (self::SCHEMA as $key => $default) {
            $value = Configuration::get(self::PREFIX . $key);
            $out[$key] = ($value === false || $value === null) ? $default : (string) $value;
        }

        return $out;
    }

    // ------------------------------------------------------------------ zakresy

    /**
     * Zakresy w kolejności ustawionej przez sprzedawcę.
     *
     * @return array<int,array{key:string,type:string,target:?int,labels:array<int,string>,enabled:bool}>
     */
    public function scopes(): array
    {
        $stored = json_decode($this->all()['SCOPES'], true);

        if (!is_array($stored) || $stored === []) {
            return $this->defaultScopes();
        }

        $out = [];

        foreach ($stored as $scope) {
            if (!is_array($scope) || !in_array((string) ($scope['type'] ?? ''), self::TYPES, true)) {
                continue;
            }

            $out[] = [
                'key' => (string) ($scope['key'] ?? $scope['type']),
                'type' => (string) $scope['type'],
                'target' => isset($scope['target']) && $scope['target'] !== '' ? (int) $scope['target'] : null,
                'labels' => isset($scope['labels']) && is_array($scope['labels']) ? $scope['labels'] : [],
                'enabled' => !isset($scope['enabled']) || (bool) $scope['enabled'],
            ];
        }

        return $out === [] ? $this->defaultScopes() : $out;
    }

    /**
     * Zakresy gotowe dla sklepu: tylko włączone, z nazwami rozwiązanymi dla języka.
     *
     * @return array<int,array{key:string,type:string,target:?int,label:string}>
     */
    public function activeScopes(int $idLang): array
    {
        $out = [];

        foreach ($this->scopes() as $scope) {
            if (!$scope['enabled']) {
                continue;
            }

            $out[] = [
                'key' => $scope['key'],
                'type' => $scope['type'],
                'target' => $scope['target'],
                'label' => $this->translate($scope['labels'], $idLang, $this->defaultLabel($scope['type'])),
            ];
        }

        return $out;
    }

    /**
     * Nazwa zakresu, kiedy sklep swojej nie wpisał.
     *
     * Idzie przez tłumacza sklepu, a nie prosto ze stałej. Inaczej polski sklep
     * pokazuje przy polu wyszukiwania „Everywhere".
     */
    private function defaultLabel(string $type): string
    {
        $etykieta = self::TYPE_LABELS[$type] ?? $type;
        $context = \Context::getContext();

        if ($context === null || $context->getTranslator() === null) {
            return $etykieta;
        }

        return $context->getTranslator()->trans($etykieta, [], 'Modules.Quincesearch.Shop');
    }

    /** Zakres wskazany w adresie. Nieznany klucz nie jest błędem: sklep szuka wszędzie. */
    public function scopeByKey(string $key, int $idLang): ?array
    {
        foreach ($this->activeScopes($idLang) as $scope) {
            if ($scope['key'] === $key) {
                return $scope;
            }
        }

        return null;
    }

    /** @return array<string,string> */
    public function typeLabels(): array
    {
        return self::TYPE_LABELS;
    }

    // ------------------------------------------------------------------ puste wyniki

    /**
     * Ustawienia strony bez wyników.
     *
     * Kategorie wskazuje sklep, nie dobiera ich żadna reguła. „Najpopularniejsze"
     * brzmi dobrze, ale popularność można liczyć na trzy sposoby i każdy daje
     * inną listę, więc wybór zostaje przy tym, kto zna swój katalog.
     *
     * @return array{enabled:bool,tips:array<int,string>,cats_title:array<int,string>,
     *               cats_ids:array<int,int>,cats_per_row:int,icon_mode:bool}
     */
    public function emptyState(): array
    {
        $stored = json_decode($this->all()['EMPTY'], true);
        $stored = is_array($stored) ? $stored : [];

        return [
            'enabled' => !isset($stored['enabled']) || (bool) $stored['enabled'],
            'tips' => isset($stored['tips']) && is_array($stored['tips']) ? $stored['tips'] : [],
            'cats_title' => isset($stored['cats_title']) && is_array($stored['cats_title']) ? $stored['cats_title'] : [],
            'cats_ids' => array_values(array_filter(array_map('intval', (array) ($stored['cats_ids'] ?? [])))),
            'cats_per_row' => $this->wZakresieRzedu((int) ($stored['cats_per_row'] ?? self::CATS_PER_ROW_DEFAULT)),
            'icon_mode' => (bool) ($stored['icon_mode'] ?? false),
        ];
    }

    private function wZakresieRzedu(int $ile): int
    {
        return max(self::CATS_PER_ROW_MIN, min(self::CATS_PER_ROW_MAX, $ile));
    }

    /**
     * Puste wyniki gotowe dla sklepu: wskazówki rozbite na pozycje listy,
     * tytuł rozwiązany dla języka.
     *
     * @return array<string,mixed>
     */
    public function emptyStateFor(int $idLang): array
    {
        $state = $this->emptyState();

        $tips = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R/', $this->translate($state['tips'], $idLang, '')) ?: []
        ), static fn (string $t): bool => $t !== ''));

        return [
            'enabled' => $state['enabled'],
            'tips' => $tips,
            'cats_title' => $this->translate($state['cats_title'], $idLang, ''),
            'cats_ids' => $state['cats_ids'],
            'cats_per_row' => $state['cats_per_row'],
            'icon_mode' => $state['icon_mode'],
        ];
    }

    // ------------------------------------------------------------------ zapis

    /** @param array<string,mixed> $values */
    public function save(array $values): void
    {
        ConfigStore::set(self::PREFIX . 'SCOPES', (string) json_encode($this->readScopes($values)), true);
        ConfigStore::set(self::PREFIX . 'EMPTY', (string) json_encode($this->readEmptyState($values)), true);

        ConfigStore::set(self::PREFIX . 'SUGGEST_ENABLED', (string) ($values['SUGGEST_ENABLED'] ?? '0'));
        ConfigStore::set(self::PREFIX . 'SUGGEST_MIN_CHARS', (string) max(1, min(6, (int) ($values['SUGGEST_MIN_CHARS'] ?? 3))));
        ConfigStore::set(self::PREFIX . 'SUGGEST_LIMIT', (string) max(3, min(12, (int) ($values['SUGGEST_LIMIT'] ?? 6))));
    }

    /** @return string[] */
    public function configurationKeys(): array
    {
        return array_map(static fn (string $k): string => self::PREFIX . $k, array_keys(self::SCHEMA));
    }

    // ------------------------------------------------------------------ pomocnicze

    /**
     * @param array<string,mixed> $values
     *
     * @return array<int,array<string,mixed>>
     */
    private function readScopes(array $values): array
    {
        $rows = $values['scope'] ?? [];
        $out = [];
        $used = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !in_array((string) ($row['type'] ?? ''), self::TYPES, true)) {
                continue;
            }

            $type = (string) $row['type'];
            $labels = [];

            foreach ((array) ($row['labels'] ?? []) as $idLang => $label) {
                $labels[(int) $idLang] = (string) $label;
            }

            // Klucz trafia do adresu, więc musi być jednoznaczny i bez znaków
            // wymagających kodowania. Pusty albo powtórzony dostaje własny numer:
            // dwa zakresy o tym samym kluczu znaczyłyby, że jednego nie da się wybrać.
            $key = $this->slug((string) ($row['key'] ?? ''), $type);

            if (isset($used[$key])) {
                $key .= '-' . (++$used[$key]);
            }

            $used[$key] = $used[$key] ?? 1;

            $out[] = [
                'key' => $key,
                'type' => $type,
                'target' => in_array($type, self::TYPES_WITH_TARGET, true) && !empty($row['target'])
                    ? (int) $row['target']
                    : null,
                'labels' => $labels,
                'enabled' => (string) ($row['enabled'] ?? '0') === '1',
            ];
        }

        return $out === [] ? $this->defaultScopes() : $out;
    }

    /**
     * @param array<string,mixed> $values
     *
     * @return array<string,mixed>
     */
    private function readEmptyState(array $values): array
    {
        $tips = [];
        $title = [];

        foreach ((array) ($values['empty_tips'] ?? []) as $idLang => $value) {
            $tips[(int) $idLang] = (string) $value;
        }

        foreach ((array) ($values['empty_cats_title'] ?? []) as $idLang => $value) {
            $title[(int) $idLang] = (string) $value;
        }

        return [
            'enabled' => (string) ($values['empty_enabled'] ?? '0') === '1',
            'tips' => $tips,
            'cats_title' => $title,
            'cats_ids' => array_values(array_unique(array_filter(array_map('intval', (array) ($values['empty_cats_ids'] ?? []))))),
            'cats_per_row' => $this->wZakresieRzedu((int) ($values['empty_cats_per_row'] ?? self::CATS_PER_ROW_DEFAULT)),
            'icon_mode' => (string) ($values['empty_icon_mode'] ?? '0') === '1',
        ];
    }

    /**
     * Klucz do adresu. Zamiana idzie przez `Tools::str2url()`, bo ta funkcja
     * przepisuje znaki narodowe na łacińskie. Własne wycinanie zamieniłoby
     * „Wszędzie" na „wsz-dzie", czyli na klucz, którego nikt nie rozpozna.
     */
    private function slug(string $value, string $fallback): string
    {
        $slug = trim((string) \Tools::str2url($value));
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $slug));
        $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');

        return $slug !== '' ? $slug : $fallback;
    }

    /** @return array<int,array{key:string,type:string,target:?int,labels:array<int,string>,enabled:bool}> */
    private function defaultScopes(): array
    {
        $out = [];

        // Cecha produktu nie wchodzi do zestawu domyślnego: wymaga wskazania
        // konkretnej cechy, a tej na świeżo zainstalowanym sklepie nie znamy.
        foreach (['all', 'name', 'reference', 'category', 'manufacturer'] as $type) {
            $out[] = [
                'key' => $type,
                'type' => $type,
                'target' => null,
                'labels' => [],
                'enabled' => true,
            ];
        }

        return $out;
    }

    /** @param array<int,string> $values */
    private function translate(array $values, int $idLang, string $fallback): string
    {
        if (isset($values[$idLang]) && trim($values[$idLang]) !== '') {
            return $values[$idLang];
        }

        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return $fallback;
    }
}
