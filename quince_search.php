<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use PrestaShop\PrestaShop\Adapter\Presenter\Category\CategoryPresenter;
use Quince\Search\Settings\SearchSettings;

/**
 * Quince Search — wyszukiwarka z wyborem zakresu i podpowiedziami.
 *
 * Nakładka na wyszukiwanie rdzenia, nie jego zamiennik: PrestaShop zostaje przy
 * swoim indeksie i swoich regułach trafności, a moduł zawęża pulę wyników do
 * wybranego zakresu i dokłada podpowiedzi w trakcie pisania.
 *
 * Moduł zgłasza się silnikowi hookiem `actionQuinceRegisterFeature`. Dzięki temu
 * ustawienia motywu pokazują wyszukiwarkę na liście dopiero wtedy, gdy naprawdę
 * jest zainstalowana — a po odinstalowaniu sklep wraca do wyszukiwarki wbudowanej
 * zamiast zostać bez żadnej.
 */
class Quince_Search extends Module
{
    public function __construct()
    {
        $this->name = 'quince_search';
        $this->tab = 'front_office_features';
        $this->version = '0.1.0';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];

        // Silnik nie jest opcjonalny: to on rysuje pole wyszukiwania w nagłówku
        // i to jego panel udostępnia ustawienia. Zadeklarowanie zależności daje
        // czytelny błąd przy instalacji zamiast modułu, który po cichu nie działa.
        $this->dependencies = ['quince_engine'];

        // Jeden wpis w gałęzi „Quince project" w menu PrestaShopa, prowadzący
        // na ekran modułu. Podstron nie zgłaszamy: moduł ma je we własnym
        // bocznym pasku, a menu sklepu urosłoby wtedy o kilkanaście pozycji.
        // Kolejność wpisów w gałęzi ustala silnik.
        $this->tabs = [
            [
                'class_name' => 'AdminQuinceSearch',
                'visible' => true,
                'name' => 'Search',
                'parent_class_name' => 'AdminQuinceProject',
                'route_name' => 'quince_search_settings',
                'wording' => 'Search',
                'wording_domain' => 'Modules.Quincesearch.Admin',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('Quince Search', [], 'Modules.Quincesearch.Admin');
        $this->description = $this->trans(
            'Search with a scope filter and product suggestions. Requires Quince Engine.',
            [],
            'Modules.Quincesearch.Admin'
        );
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('actionQuinceRegisterFeature')
            && $this->registerHook('actionFrontControllerSetVariables')
            && $this->registerHook('productSearchProvider')
            && $this->pierwszyWSzukaniu()
            && $this->installModuleGroups();
    }

    public function uninstall(): bool
    {
        foreach ((new SearchSettings())->configurationKeys() as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /**
     * Zgłoszenie do silnika: co dostarczamy i gdzie są nasze ustawienia.
     *
     * @return array<int,array<string,string>>
     */
    public function hookActionQuinceRegisterFeature(): array
    {
        return [[
            'type' => 'search',
            'id' => 'quince_search',
            'label' => $this->trans('Quince search', [], 'Modules.Quincesearch.Admin'),
            'route' => 'quince_search_settings',
        ]];
    }

    /**
     * Zakresy dla pola wyszukiwania w nagłówku. Motyw dostaje gotową listę
     * z nazwami rozwiązanymi dla bieżącego języka — w szablonie nic się nie liczy.
     *
     * @return array<string,mixed>
     */
    public function hookActionFrontControllerSetVariables(): array
    {
        $settings = new SearchSettings();
        $all = $settings->all();

        return [
            'scopes' => $settings->activeScopes((int) $this->context->language->id),

            // Strona bez wyników tylko tam, gdzie klient może nic nie znaleźć.
            // Hook odpala się na każdej stronie sklepu, a składanie kafelków
            // kategorii na karcie produktu byłoby pracą do wyrzucenia.
            'empty' => $this->pustyWynik($settings),

            'suggest' => [
                'enabled' => $all['SUGGEST_ENABLED'] === '1',
                'min_chars' => (int) $all['SUGGEST_MIN_CHARS'],
                'limit' => (int) $all['SUGGEST_LIMIT'],

                // Adres składa PrestaShop, bo zna przepisywanie adresów, język
                // i sklep w wielosklepie. Wpisany na sztywno działałby do pierwszej
                // zmiany ustawień URL-i.
                'url' => $this->context->link->getModuleLink('quince_search', 'suggest', [], true),
            ],
        ];
    }

    /**
     * Co pokazać klientowi, który nic nie znalazł.
     *
     * Wskazówki i nagłówek pisze sklep, kategorie wskazuje z katalogu. Kafelki
     * przedstawiamy tym samym prezenterem co podkategorie, bo rysuje je ten sam
     * kawałek motywu — inaczej te same kategorie wyglądałyby na dwóch stronach
     * inaczej.
     *
     * @return array<string,mixed>
     */
    private function pustyWynik(SearchSettings $settings): array
    {
        if (($this->context->controller->php_self ?? '') !== 'search') {
            return ['enabled' => false, 'tips' => [], 'cats_title' => '', 'categories' => [], 'icon_mode' => false];
        }

        $stan = $settings->emptyStateFor((int) $this->context->language->id);
        $stan['categories'] = $stan['enabled'] ? $this->kategorie($stan['cats_ids']) : [];

        unset($stan['cats_ids']);

        return $stan;
    }

    /**
     * Wskazane kategorie w postaci, której oczekuje rząd kafelków w motywie.
     *
     * Kolejność idzie za katalogiem, a nie za kolejnością klikania w panelu:
     * drzewo zwraca zaznaczenia tak, jak stoją w katalogu, i tak samo mają
     * stać na stronie.
     *
     * @param int[] $ids
     *
     * @return array<int,mixed>
     */
    private function kategorie(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $presenter = new CategoryPresenter($this->context->link);
        $jezyk = (int) $this->context->language->id;
        $out = [];

        foreach ($ids as $id) {
            $kategoria = new Category((int) $id, $jezyk);

            // Kategoria skasowana albo wyłączona po zapisaniu ustawień: wpis
            // w ustawieniach zostaje, ale klient nie ma po co tam trafić.
            if (!Validate::isLoadedObject($kategoria) || !$kategoria->active) {
                continue;
            }

            $out[] = $presenter->present($kategoria, $this->context->language);
        }

        return $out;
    }

    /**
     * Pierwsze miejsce w kolejce dostawców wyszukiwania.
     *
     * Rdzeń pyta wszystkie moduły i bierze PIERWSZĄ odpowiedź różną od null —
     * nie najlepszą, tylko pierwszą. Filtrowanie (`ps_facetedsearch`) zgłasza się
     * do tego samego haka i stojąc wcześniej odbierałoby nam każde pytanie.
     * Stajemy więc przed nim i oddajemy głos przy każdym pytaniu, które nas nie
     * dotyczy — czyli wszędzie tam, gdzie klient nie zawęził zakresu.
     */
    private function pierwszyWSzukaniu(): bool
    {
        $idHook = (int) Hook::getIdByName('productSearchProvider');

        return $idHook <= 0 || $this->updatePosition($idHook, false, 1);
    }

    /**
     * Strona wyników dla zawężonego zakresu.
     *
     * Zwrócenie `null` znaczy „nie mam nic do dodania" i wtedy szuka rdzeń.
     * Robimy to zawsze, gdy klient nie wybrał zakresu albo wybrał „wszędzie":
     * indeks rdzenia zna wagi pól i literówki, więc podmienianie go własnym
     * zapytaniem byłoby zamianą lepszego na gorsze.
     *
     * @param array<string,mixed> $params
     */
    public function hookProductSearchProvider(array $params)
    {
        $query = $params['query'] ?? null;

        if (!$query instanceof \PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery) {
            return null;
        }

        if ($query->getQueryType() !== 'search') {
            return null;
        }

        $klucz = trim((string) Tools::getValue('q_scope'));

        if ($klucz === '') {
            return null;
        }

        $settings = new SearchSettings();
        $zakres = $settings->scopeByKey($klucz, (int) $this->context->language->id);

        if ($zakres === null || $zakres['type'] === 'all') {
            return null;
        }

        return new \Quince\Search\Search\ScopeSearchProvider(new \Quince\Search\Search\ProductFinder(), $zakres);
    }

    /**
     * Wpisy w ps_module_group.
     *
     * Bez nich hook frontendowy w ogóle nie odpala: PrestaShop dokłada do listy
     * modułów warunek na grupę klienta i moduł bez wpisów znika z wyników.
     * Lokalnie bywa niewidoczne, na czystym sklepie nie.
     */
    private function installModuleGroups(): bool
    {
        $groups = Group::getGroups((int) Context::getContext()->language->id, true);
        $shops = Shop::getShops(true, null, true);

        foreach ($shops as $idShop) {
            foreach ($groups as $group) {
                Db::getInstance()->insert(
                    'module_group',
                    [
                        'id_module' => (int) $this->id,
                        'id_shop' => (int) $idShop,
                        'id_group' => (int) $group['id_group'],
                    ],
                    false,
                    true,
                    Db::INSERT_IGNORE
                );
            }
        }

        return true;
    }

    /**
     * Przycisk „Konfiguruj" w menedżerze modułów.
     *
     * PrestaShop pokazuje go po samym istnieniu tej metody
     * (`ModuleRepository::__construct`, `method_exists($tmpModule, 'getContent')`),
     * a my przenosimy sprzedawcę na ekran modułu w panelu.
     */
    public function getContent(): void
    {
        \Tools::redirectAdmin($this->get('router')->generate('quince_search_settings'));
    }

}
