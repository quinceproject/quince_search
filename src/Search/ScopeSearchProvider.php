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

use Combination;
use Configuration;
use Context;
use Db;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;
use Product;
use Shop;
use Validate;

/**
 * Strona wyników dla zawężonego zakresu.
 *
 * Wchodzi do gry tylko wtedy, gdy klient wybrał zakres inny niż „wszędzie".
 * Bez zakresu wyniki zostają przy rdzeniu, z jego indeksem i wagami pól.
 *
 * Zakres zawęża pulę produktów tą samą drogą co podpowiedzi (ProductFinder),
 * więc lista pod polem i strona po wciśnięciu Enter pokazują to samo. Rozjazd
 * między jednym a drugim jest gorszy niż brak podpowiedzi.
 */
final class ScopeSearchProvider implements ProductSearchProviderInterface
{
    /** Pula, z której stronicujemy. Wyżej i tak nikt nie schodzi. */
    private const MAX_PULA = 300;

    private ProductFinder $finder;

    /** @var array{key:string,type:string,target:?int,label:string} */
    private array $scope;

    /**
     * @param array{key:string,type:string,target:?int,label:string} $scope
     */
    public function __construct(ProductFinder $finder, array $scope)
    {
        $this->finder = $finder;
        $this->scope = $scope;
    }

    public function runQuery(ProductSearchContext $context, ProductSearchQuery $query): ProductSearchResult
    {
        $wynik = new ProductSearchResult();

        $ids = $this->finder->ids(
            (string) $query->getSearchString(),
            $this->scope,
            (int) $context->getIdLang(),
            (int) $context->getIdShop(),
            self::MAX_PULA
        );

        $wynik->setTotalProductsCount(count($ids));
        $wynik->setAvailableSortOrders($this->sortowania());

        if (!$ids) {
            return $wynik;
        }

        $strona = max(1, (int) $query->getPage());
        $naStronie = max(1, (int) $query->getResultsPerPage());
        $wycinek = array_slice($ids, ($strona - 1) * $naStronie, $naStronie);

        if (!$wycinek) {
            return $wynik;
        }

        $wynik->setProducts($this->produkty($wycinek, (int) $context->getIdLang(), (int) $context->getIdShop()));

        return $wynik;
    }

    /**
     * Pełne dane produktów w kolejności trafności.
     *
     * Zestaw pól jest ten sam, którego używa wyszukiwanie rdzenia: prezenter
     * produktu oczekuje dokładnie tych kolumn, a brak choćby jednej kończy się
     * kartą bez ceny albo bez zdjęcia.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     */
    private function produkty(array $ids, int $idLang, int $idShop): array
    {
        $lista = implode(',', array_map('intval', $ids));
        $nowosc = Validate::isUnsignedInt(Configuration::get('PS_NB_DAYS_NEW_PRODUCT'))
            ? (int) Configuration::get('PS_NB_DAYS_NEW_PRODUCT')
            : 20;

        $warianty = Combination::isFeatureActive();

        $sql = 'SELECT p.*, product_shop.*, stock.out_of_stock, IFNULL(stock.quantity, 0) as quantity,
                pl.description_short, pl.available_now, pl.available_later, pl.link_rewrite, pl.name,
                image_shop.id_image id_image, il.legend, m.name manufacturer_name,
                DATEDIFF(p.date_add, DATE_SUB("' . date('Y-m-d') . ' 00:00:00", INTERVAL ' . $nowosc . ' DAY)) > 0 new'
            . ($warianty ? ', product_attribute_shop.minimal_quantity AS product_attribute_minimal_quantity,
                IFNULL(product_attribute_shop.id_product_attribute, 0) id_product_attribute' : '') . '
            FROM ' . _DB_PREFIX_ . 'product p
            ' . Shop::addSqlAssociation('product', 'p') . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON p.id_product = pl.id_product AND pl.id_lang = ' . $idLang . Shop::addSqlRestrictionOnLang('pl') . '
            ' . ($warianty ? 'LEFT JOIN ' . _DB_PREFIX_ . 'product_attribute_shop product_attribute_shop
                ON (p.id_product = product_attribute_shop.id_product
                    AND product_attribute_shop.default_on = 1
                    AND product_attribute_shop.id_shop = ' . $idShop . ')' : '') . '
            ' . Product::sqlStock('p', 0) . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON m.id_manufacturer = p.id_manufacturer
            LEFT JOIN ' . _DB_PREFIX_ . 'image_shop image_shop
                ON (image_shop.id_product = p.id_product AND image_shop.cover = 1 AND image_shop.id_shop = ' . $idShop . ')
            LEFT JOIN ' . _DB_PREFIX_ . 'image_lang il
                ON (image_shop.id_image = il.id_image AND il.id_lang = ' . $idLang . ')
            WHERE p.id_product IN (' . $lista . ')
            GROUP BY product_shop.id_product
            ORDER BY FIELD(p.id_product, ' . $lista . ')';

        $wiersze = Db::getInstance()->executeS($sql, true, false) ?: [];

        return Product::getProductsProperties($idLang, $wiersze);
    }

    /**
     * Sposoby porządkowania oferowane klientowi.
     *
     * Trafność nie jest tu jedną z opcji: zakres zawęża pulę do jednego pola,
     * więc wszystkie trafienia są tak samo trafne. Zostają kryteria, które
     * naprawdę różnicują: nazwa i cena.
     *
     * @return SortOrder[]
     */
    private function sortowania(): array
    {
        // Tłumaczy kontekst sklepu, nie `Tools::displayError()`: ta metoda
        // w trybie deweloperskim RZUCA wyjątkiem zamiast oddać tekst
        // i wywraca całą stronę wyników.
        $t = Context::getContext()->getTranslator();

        return [
            (new SortOrder('product', 'name', 'asc'))->setLabel(
                $t->trans('Name, A to Z', [], 'Shop.Theme.Global')
            ),
            (new SortOrder('product', 'name', 'desc'))->setLabel(
                $t->trans('Name, Z to A', [], 'Shop.Theme.Global')
            ),
            (new SortOrder('product', 'price', 'asc'))->setLabel(
                $t->trans('Price, low to high', [], 'Shop.Theme.Global')
            ),
            (new SortOrder('product', 'price', 'desc'))->setLabel(
                $t->trans('Price, high to low', [], 'Shop.Theme.Global')
            ),
        ];
    }
}
