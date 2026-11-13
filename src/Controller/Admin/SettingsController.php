<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Search\Controller\Admin;

use Db;
use Quince\Engine\Controller\Admin\QuinceController;
use Quince\Search\Settings\SearchSettings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ustawienia wyszukiwarki.
 *
 * Dziedziczy po klasie z silnika, więc strona dostaje ten sam sidebar, pasek
 * zapisu i wygląd co reszta panelu Quince.
 */
class SettingsController extends QuinceController
{
    public function __construct(private readonly SearchSettings $settings)
    {
    }

    public function indexAction(): Response
    {
        $idLang = (int) $this->getContext()->language->id;

        return $this->renderPanel('@Modules/quince_search/views/templates/admin/settings.html.twig', [
            'settings' => $this->settings->all(),
            'scopes' => $this->settings->scopes(),
            'typeLabels' => $this->settings->typeLabels(),
            'typesWithTarget' => SearchSettings::TYPES_WITH_TARGET,
            'features' => $this->features($idLang),
            'emptyState' => $this->settings->emptyState(),
            'limits' => [
                'catsPerRowMin' => SearchSettings::CATS_PER_ROW_MIN,
                'catsPerRowMax' => SearchSettings::CATS_PER_ROW_MAX,
            ],
            'categories' => $this->categories($idLang),
            'languages' => $this->languages(),
            'layoutTitle' => 'Search',
            'quinceRoute' => 'quince_search_settings',
        ]);
    }

    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_search_settings_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', 'Modules.Quincesearch.Admin'));

            return $this->redirectToRoute('quince_search_settings');
        }

        $this->settings->save($request->request->all());
        $this->addFlash('success', $this->trans('Settings saved.', 'Modules.Quincesearch.Admin'));

        return $this->redirectToRoute('quince_search_settings');
    }

    /**
     * Cechy produktu dla zakresu, który szuka po konkretnej cesze.
     *
     * @return array<int,array{id:int,name:string}>
     */
    private function features(int $idLang): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT f.id_feature, fl.name
             FROM ' . _DB_PREFIX_ . 'feature f
             INNER JOIN ' . _DB_PREFIX_ . 'feature_lang fl
                 ON fl.id_feature = f.id_feature AND fl.id_lang = ' . $idLang . '
             ORDER BY fl.name'
        );

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id_feature'], 'name' => (string) $r['name']],
            is_array($rows) ? $rows : []
        );
    }

    /**
     * Drzewo kategorii do wyboru na stronie pustych wyników.
     *
     * Wybór z drzewa, a nie wpisywanie numerów: „434,690,691" nie mówi nikomu nic
     * i po zmianie katalogu nikt tego nie zweryfikuje.
     *
     * @return array<int,array{id:int,name:string,depth:int}>
     */
    private function categories(int $idLang): array
    {
        $idShop = (int) $this->getContext()->shop->id;

        // Złączenie po języku ORAZ po sklepie: category_lang ma wiersz na każdą
        // parę język–sklep, więc samo id_lang daje tyle kopii każdej kategorii,
        // ile sklep ma sklepów. Przy czterech sklepach lista rośnie czterokrotnie.
        $rows = Db::getInstance()->executeS(
            'SELECT c.id_category, c.level_depth, cl.name
             FROM ' . _DB_PREFIX_ . 'category c
             INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl
                 ON cl.id_category = c.id_category
                 AND cl.id_lang = ' . $idLang . '
                 AND cl.id_shop = ' . $idShop . '
             INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs
                 ON cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . '
             WHERE c.active = 1 AND c.level_depth > 1
             ORDER BY c.nleft'
        );

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id_category'],
                'name' => (string) $r['name'],
                // Poziom liczony od kategorii głównej, żeby wcięcie zaczynało się od zera.
                'depth' => max(0, (int) $r['level_depth'] - 2),
            ],
            is_array($rows) ? $rows : []
        );
    }

}
