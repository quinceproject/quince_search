<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Search\Search;

use Configuration;
use Context;
use Db;
use Language;
use Link;
use Module;
use Product;
use Tools;

/**
 * Szukanie produktów dla podpowiedzi i dla zawężonych zakresów.
 *
 * Dwie drogi, bo to dwa różne pytania:
 *
 * 1. Zakres „wszędzie" pyta INDEKS rdzenia (`ps_search_word` + `ps_search_index`).
 *    To ten sam zbiór i ta sama kolejność, którą pokaże strona wyników, więc
 *    podpowiedź nie obiecuje czegoś, czego po wciśnięciu Enter nie widać.
 *    Indeks zna wagi pól ustawione w sklepie, odmianę słów i literówki.
 *
 * 2. Zakresy zawężone pytają WPROST tabele, których dotyczą: nazwę, kod, drzewo
 *    kategorii, markę albo wartość cechy. Indeks miesza wszystkie pola w jedną
 *    sumę wag, więc nie da się z niego wyciąć „tylko po kodzie".
 *
 * Wspólne dla obu dróg jest to, co decyduje o tym, czy klient MOŻE zobaczyć
 * produkt: sklep, aktywność, widoczność w wyszukiwarce i prawo grupy klienta do
 * kategorii. Te warunki dokłada jedno miejsce, żeby żaden zakres nie wypuścił
 * produktu, którego strona wyników by nie pokazała.
 */
final class ProductFinder
{
    /** Dłuższej frazy nie ma sensu ani szukać, ani przepuszczać do bazy. */
    private const MAX_PHRASE = 120;

    /**
     * Produkty pasujące do frazy, gotowe do pokazania.
     *
     * @param array{type:string,target:?int}|null $scope null albo typ 'all': cały katalog
     *
     * @return array<int,array<string,mixed>>
     */
    public function find(string $phrase, ?array $scope, int $idLang, int $idShop, int $limit): array
    {
        $ids = $this->ids($phrase, $scope, $idLang, $idShop, $limit);

        return $ids ? $this->rows($ids, $idLang, $idShop) : [];
    }

    /**
     * Numery produktów w kolejności trafności.
     *
     * @param array{type:string,target:?int}|null $scope
     *
     * @return int[]
     */
    public function ids(string $phrase, ?array $scope, int $idLang, int $idShop, int $limit): array
    {
        $phrase = $this->clean($phrase);

        if ($phrase === '') {
            return [];
        }

        $type = $scope['type'] ?? 'all';

        return $type === 'all'
            ? $this->byIndex($phrase, $idLang, $idShop, $limit)
            : $this->byScope($type, (int) ($scope['target'] ?? 0), $phrase, $idLang, $idShop, $limit);
    }

    /** Fraza obcięta do rozsądnej długości i pozbawiona znaczników. */
    private function clean(string $phrase): string
    {
        $phrase = trim(strip_tags($phrase));

        return $phrase === '' ? '' : Tools::substr($phrase, 0, self::MAX_PHRASE);
    }

    /**
     * Droga przez indeks rdzenia.
     *
     * Fraza rozbita na słowa, każde szukane jako początek wyrazu (`LIKE 'słowo%'`),
     * tak samo jak robi to rdzeń. Produkt musi trafić w KAŻDE słowo, inaczej
     * „komputer iveco" pokazywałby wszystko, co ma w opisie samo „iveco".
     *
     * @return int[]
     */
    private function byIndex(string $phrase, int $idLang, int $idShop, int $limit): array
    {
        $words = $this->words($phrase);

        if (!$words) {
            return [];
        }

        $warunki = [];

        foreach ($words as $word) {
            $warunki[] = "sw.word LIKE '" . pSQL($word) . "%'";
        }

        // Score z `quince_search_score`, gdy moduł jest zainstalowany. Celowo
        // SŁABY sygnał: rozstrzyga remisy trafności, nie przebija jej. Produkt
        // z wyższym score nie wyprzedzi trafniejszego dopasowania, tylko
        // wygrywa, gdy trafność jest taka sama. Bez modułu zapytanie idzie
        // bez JOIN-a.
        $scoreJoin = '';
        $scoreOrder = '';

        if (Module::getInstanceByName('quince_search_score')) {
            $scoreJoin = ' LEFT JOIN ' . _DB_PREFIX_ . 'quince_search_score_products qss
                ON qss.id_product = si.id_product';
            $scoreOrder = 'COALESCE(qss.score, 0) DESC, ';
        }

        $sql = 'SELECT si.id_product, SUM(si.weight) AS trafnosc
            FROM ' . _DB_PREFIX_ . 'search_word sw
            INNER JOIN ' . _DB_PREFIX_ . 'search_index si ON si.id_word = sw.id_word
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps
                ON ps.id_product = si.id_product AND ps.id_shop = ' . (int) $idShop . '
            ' . $scoreJoin . '
            WHERE sw.id_lang = ' . (int) $idLang . '
              AND sw.id_shop = ' . (int) $idShop . '
              AND (' . implode(' OR ', $warunki) . ')
              AND ' . $this->widocznosc() . '
              AND si.id_product IN (' . $this->dostepneProdukty($idShop) . ')
            GROUP BY si.id_product
            HAVING COUNT(DISTINCT sw.id_word) >= ' . count($words) . '
            ORDER BY trafnosc DESC, ' . $scoreOrder . 'si.id_product ASC
            LIMIT ' . (int) $limit;

        return $this->numery($sql);
    }

    /**
     * Droga przez tabelę właściwą dla zakresu.
     *
     * @return int[]
     */
    private function byScope(string $type, int $target, string $phrase, int $idLang, int $idShop, int $limit): array
    {
        $like = "'%" . pSQL($phrase) . "%'";

        $baza = 'SELECT DISTINCT p.id_product
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps
                ON ps.id_product = p.id_product AND ps.id_shop = ' . (int) $idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON pl.id_product = p.id_product AND pl.id_lang = ' . (int) $idLang . '
                AND pl.id_shop = ' . (int) $idShop . ' ';

        switch ($type) {
            case 'name':
                $zlaczenia = '';
                $warunek = 'pl.name LIKE ' . $like;
                break;

            // Kod bywa wpisywany z myślnikami i spacjami, których w bazie nie ma,
            // dlatego szukamy też po ciągu bez separatorów.
            case 'reference':
                $goly = preg_replace('/[^a-z0-9]/i', '', $phrase);
                $golyLike = $goly === '' ? $like : "'%" . pSQL($goly) . "%'";

                $zlaczenia = 'LEFT JOIN ' . _DB_PREFIX_ . 'product_attribute pa
                    ON pa.id_product = p.id_product ';
                $warunek = '(p.reference LIKE ' . $like . ' OR p.reference LIKE ' . $golyLike . '
                    OR p.ean13 LIKE ' . $golyLike . ' OR p.isbn LIKE ' . $golyLike . '
                    OR p.upc LIKE ' . $golyLike . ' OR p.mpn LIKE ' . $golyLike . '
                    OR pa.reference LIKE ' . $like . ' OR pa.ean13 LIKE ' . $golyLike . ')';
                break;

            case 'category':
                $zlaczenia = 'INNER JOIN ' . _DB_PREFIX_ . 'category_product cp
                        ON cp.id_product = p.id_product
                    INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl
                        ON cl.id_category = cp.id_category AND cl.id_lang = ' . (int) $idLang . '
                        AND cl.id_shop = ' . (int) $idShop . '
                    INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs
                        ON cs.id_category = cp.id_category AND cs.id_shop = ' . (int) $idShop . ' ';
                $warunek = 'cl.name LIKE ' . $like;
                break;

            case 'manufacturer':
                $zlaczenia = 'INNER JOIN ' . _DB_PREFIX_ . 'manufacturer m
                    ON m.id_manufacturer = p.id_manufacturer ';
                $warunek = 'm.name LIKE ' . $like;
                break;

            // Cecha bez wskazanego rodzaju szukałaby po wszystkich naraz, czyli
            // po niczym konkretnym. Zakres bez celu nie zwraca nic.
            case 'feature':
                if ($target <= 0) {
                    return [];
                }

                $zlaczenia = 'INNER JOIN ' . _DB_PREFIX_ . 'feature_product fp
                        ON fp.id_product = p.id_product AND fp.id_feature = ' . $target . '
                    INNER JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                        ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . (int) $idLang . ' ';
                $warunek = 'fvl.value LIKE ' . $like;
                break;

            default:
                return [];
        }

        $sql = $baza . $zlaczenia . '
            WHERE ' . $warunek . '
              AND ' . $this->widocznosc() . '
              AND p.id_product IN (' . $this->dostepneProdukty($idShop) . ')
            ORDER BY pl.name ASC
            LIMIT ' . (int) $limit;

        return $this->numery($sql);
    }

    /** Warunek wspólny: produkt włączony i dopuszczony do wyszukiwarki. */
    private function widocznosc(): string
    {
        return "ps.active = 1 AND ps.visibility IN ('both', 'search')";
    }

    /**
     * Produkty, które wolno pokazać tej grupie klientów.
     *
     * Kategoria bez prawa dostępu ukrywa produkt na listingu i na stronie wyników,
     * więc podpowiedź prowadząca do niego kończyłaby się stroną „nie znaleziono".
     */
    private function dostepneProdukty(int $idShop): string
    {
        $grupy = Context::getContext()->customer !== null
            ? Context::getContext()->customer->getGroups()
            : [(int) Configuration::get('PS_UNIDENTIFIED_GROUP')];

        $grupy = array_map('intval', $grupy ?: [(int) Configuration::get('PS_UNIDENTIFIED_GROUP')]);

        return 'SELECT cp.id_product
            FROM ' . _DB_PREFIX_ . 'category_product cp
            INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs
                ON cs.id_category = cp.id_category AND cs.id_shop = ' . (int) $idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'category c
                ON c.id_category = cp.id_category AND c.active = 1
            INNER JOIN ' . _DB_PREFIX_ . 'category_group cg
                ON cg.id_category = cp.id_category AND cg.id_group IN (' . implode(',', $grupy) . ')';
    }

    /**
     * Słowa frazy, bez tych, których indeks i tak nie zna.
     *
     * @return string[]
     */
    private function words(string $phrase): array
    {
        $min = (int) Configuration::get('PS_SEARCH_MINWORDLEN');
        $min = $min > 0 ? $min : 3;

        $words = preg_split('/[^\p{L}\p{N}]+/u', Tools::strtolower($phrase), -1, PREG_SPLIT_NO_EMPTY);
        $out = [];

        foreach ($words ?: [] as $word) {
            if (Tools::strlen($word) >= $min) {
                $out[] = $word;
            }
        }

        // Jedno słowo krótsze od progu to zwykle kod albo skrót. Szukamy po nim
        // mimo wszystko, bo klient wpisał je świadomie.
        if (!$out && $words) {
            $out[] = $words[0];
        }

        return $out;
    }

    /**
     * @return int[]
     */
    private function numery(string $sql): array
    {
        $wiersze = Db::getInstance()->executeS($sql);

        return array_map(static fn ($w) => (int) $w['id_product'], $wiersze ?: []);
    }

    /**
     * Dane do pokazania: nazwa, adres, miniatura, kategoria, cena.
     *
     * Jedno zapytanie na cały zestaw; cena liczona osobno dla każdego produktu,
     * bo zna promocje, podatki i grupę klienta, a tego nie da się wyjąć jednym
     * złączeniem. Pozycji jest najwyżej kilkanaście, więc to kilkanaście tanich
     * wywołań, a nie zapytanie na produkt w pętli po całym katalogu.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     */
    private function rows(array $ids, int $idLang, int $idShop): array
    {
        $lista = implode(',', array_map('intval', $ids));

        $sql = 'SELECT p.id_product, pl.name, pl.link_rewrite, p.id_category_default,
                   cl.name AS kategoria, cl.link_rewrite AS kategoria_url,
                   im.id_image
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON pl.id_product = p.id_product AND pl.id_lang = ' . (int) $idLang . '
                AND pl.id_shop = ' . (int) $idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl
                ON cl.id_category = p.id_category_default AND cl.id_lang = ' . (int) $idLang . '
                AND cl.id_shop = ' . (int) $idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'image_shop im
                ON im.id_product = p.id_product AND im.cover = 1 AND im.id_shop = ' . (int) $idShop . '
            WHERE p.id_product IN (' . $lista . ')';

        $dane = [];

        foreach (Db::getInstance()->executeS($sql) ?: [] as $wiersz) {
            $dane[(int) $wiersz['id_product']] = $wiersz;
        }

        $link = Context::getContext()->link;
        $locale = Context::getContext()->getCurrentLocale();
        $waluta = Context::getContext()->currency->iso_code;

        $out = [];

        // Kolejność bierzemy z listy numerów, nie z zapytania: to ona niesie trafność.
        foreach ($ids as $id) {
            if (!isset($dane[$id])) {
                continue;
            }

            $w = $dane[$id];

            $cena = (float) Product::getPriceStatic($id, true, null, 2);
            $przed = (float) Product::getPriceStatic($id, true, null, 2, null, false, false);

            $out[] = [
                'id' => $id,
                'name' => (string) $w['name'],
                'url' => $link->getProductLink($id, $w['link_rewrite'], (int) $w['id_category_default'], null, $idLang),
                'image' => $this->obrazek($link, (int) $w['id_image'], (string) $w['link_rewrite']),
                'category' => (string) ($w['kategoria'] ?? ''),
                'price' => $locale !== null ? $locale->formatPrice($cena, $waluta) : (string) $cena,
                'price_before' => $przed > $cena && $locale !== null ? $locale->formatPrice($przed, $waluta) : '',
            ];
        }

        return $out;
    }

    /** Miniatura okładki, a gdy produkt jej nie ma, zastępczy obrazek sklepu. */
    private function obrazek(Link $link, int $idImage, string $linkRewrite): string
    {
        if ($idImage > 0) {
            return $link->getImageLink($linkRewrite, (string) $idImage, 'small_default');
        }

        $iso = Language::getIsoById((int) Context::getContext()->language->id);

        return $link->getImageLink('', ($iso ?: 'en') . '-default', 'small_default');
    }
}
