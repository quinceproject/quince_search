<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

use Quince\Search\Search\ProductFinder;
use Quince\Search\Settings\SearchSettings;

/**
 * Podpowiedzi w trakcie pisania.
 *
 * Osobny, wąski punkt wejścia. Można było uderzyć w kontroler strony wyników,
 * bo w trybie AJAX oddaje JSON — ale oddaje w nim CAŁĄ stronę: wyrenderowany
 * listing, facety, paginację i sortowania. To dziesiątki kilobajtów i pełny
 * render szablonów na każde naciśnięcie klawisza, z czego podpowiedź bierze
 * sześć nazw, a resztę wyrzuca. Tutaj wychodzi tyle, ile wchodzi do listy.
 *
 * Odpowiedź jest zawsze poprawnym JSON-em, także przy pustej frazie i przy
 * wyłączonych podpowiedziach — strona klienta ma jeden kształt do obsłużenia,
 * a nie osobną ścieżkę na każdy przypadek.
 */
class Quince_SearchSuggestModuleFrontController extends ModuleFrontController
{
    /** @var bool nie ma tu nic do wyrenderowania poza JSON-em */
    public $ajax = true;

    /** Klucz licznika w strażniku formularzy — osobny od formularzy sklepu. */
    private const LIMIT_KEY = 'quince_search_suggest';

    /** Ile podpowiedzi z jednego adresu w oknie poniżej. */
    private const LIMIT_COUNT = 300;

    /** Okno licznika w sekundach. */
    private const LIMIT_WINDOW = 600;

    public function initContent(): void
    {
        $settings = new SearchSettings();
        $wszystkie = $settings->all();

        if ($wszystkie['SUGGEST_ENABLED'] !== '1') {
            $this->oddaj(['products' => [], 'query' => '']);
        }

        // Podpowiedzi kosztują sklep zapytanie do bazy przy każdym naciśnięciu
        // klawisza, a pytającego jedno żądanie. Limit jest liberalny, bo za jednym
        // adresem stoi czasem całe biuro — ma zatrzymać wyciąganie katalogu,
        // a nie klienta, który szybko pisze. Odmowa wygląda jak brak wyników:
        // podpowiedzi są dodatkiem, nie ścieżką, którą trzeba przejść.
        $straznik = new \Quince\Engine\Form\FormGuard();

        if ($straznik->tooOften(self::LIMIT_KEY, self::LIMIT_COUNT, self::LIMIT_WINDOW)) {
            $this->oddaj(['products' => [], 'query' => '']);
        }

        $straznik->accept(self::LIMIT_KEY);

        $fraza = trim((string) Tools::getValue('q'));
        $idLang = (int) $this->context->language->id;

        // Próg liczony w znakach, nie w bajtach: „ból" to trzy znaki, a nie pięć.
        if (Tools::strlen($fraza) < max(1, (int) $wszystkie['SUGGEST_MIN_CHARS'])) {
            $this->oddaj(['products' => [], 'query' => $fraza]);
        }

        // Zakres nieznany albo pusty nie jest błędem — sklep szuka wtedy wszędzie.
        $zakres = $settings->scopeByKey((string) Tools::getValue('scope'), $idLang);

        $produkty = (new ProductFinder())->find(
            $fraza,
            $zakres,
            $idLang,
            (int) $this->context->shop->id,
            max(1, (int) $wszystkie['SUGGEST_LIMIT'])
        );

        $this->oddaj([
            'products' => $produkty,
            'query' => $fraza,
            'scope' => $zakres['key'] ?? '',
        ]);
    }

    /**
     * @param array<string,mixed> $dane
     */
    private function oddaj(array $dane): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // Podpowiedzi zależą od grupy klienta i koszyka cen, więc nie mogą
        // zamieszkać w cache pośredniczącym po drodze.
        header('Cache-Control: no-store, private');

        echo json_encode($dane, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }
}
