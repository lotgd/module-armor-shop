<?php
declare(strict_types=1);

namespace LotGD\Modules\ArmorShop;

use Doctrine\Common\Collections\ArrayCollection;

use LotGD\Core\Action;
use LotGD\Core\Game;
use LotGD\Core\Module as ModuleInterface;
use LotGD\Core\Models\Module as ModuleModel;
use LotGD\Core\Models\Viewpoint;
use LotGD\Core\Models\Scene;

use LotGD\Modules\Forms\Form;
use LotGD\Modules\Forms\FormElement;
use LotGD\Modules\Forms\FormElementOptions;
use LotGD\Modules\Forms\FormElementType;

use LotGD\Modules\SimpleInventory\Module as SimpleInventory;
use LotGD\Modules\SimpleInventory\Models\Armor;

class Module implements ModuleInterface {
    const Module = 'lotgd/module-armor-shop';

    const ArmorShopScene = 'lotgd/module-armor-shop/shop';
    const ArmorShopBuyScene = 'lotgd/module-armor-shop/buy';
    const ArmorShopSceneArrayProperty = 'lotgd/module-armor-shop/scenes';

    const ChoiceParameter = 'choice';

    const TradeInHook = 'h/lotgd/module-armor-shop/trade-in';

    public static function tradeInValue($item): int
    {
        return $item ? (int)round(($item->getCost() * .75), 0) : 0;
    }

    public static function handleEvent(Game $g, string $event, array &$context)
    {
        switch ($event) {
            case 'h/lotgd/core/navigate-to/lotgd/module-armor-shop/shop':
                ShopScene::handleViewpoint($g, $context);
                break;
            case 'h/lotgd/core/navigate-to/lotgd/module-armor-shop/buy':
                BuyScene::handleViewpoint($g, $context);
                break;
        }
    }

    private static function storeSceneId(ModuleModel $module, int $id)
    {
        $scenes = $module->getProperty(self::ArmorShopSceneArrayProperty);
        if ($scenes === null) {
            $scenes = [];
        }
        $scenes[] = $id;
        $module->setProperty(self::ArmorShopSceneArrayProperty, $scenes);
    }

    public static function onRegister(Game $g, ModuleModel $module)
    {
        // Add a shop scene as a child to every village-like scene.

        // Find the village-like scenes.
        $villages = $g->getEntityManager()->getRepository(Scene::class)->findBy([ 'template' => 'lotgd/module-village/village' ]);
        if ($villages === null || count($villages) == 0) {
            $g->getLogger()->addNotice(sprintf("%s: Couldn't find any villages to add the armor shop to", self::Module));
        } else {
            foreach ($villages as $v) {
                $g->getLogger()->addNotice(sprintf("%s: Adding a armor shop to scene id=%d", self::Module, $v->getId()));
                $shop = ShopScene::getScene();
                $shop->setParents(new ArrayCollection([$v]));
                $shop->save($g->getEntityManager());

                // Add a buying scene.
                $buy = BuyScene::getScene();
                $buy->setParents(new ArrayCollection([$shop]));
                $buy->save($g->getEntityManager());

                // Keep a list of these shop scenes we've added, so we can remove them
                // on unregistration.
                self::storeSceneId($module, $shop->getId());
                self::storeSceneId($module, $buy->getId());
            }
        }
    }

    public static function onUnregister(Game $g, ModuleModel $module)
    {
        $scenes = $module->getProperty(self::ArmorShopSceneArrayProperty);
        if ($scenes && count($scenes) > 0) {
            foreach ($scenes as $id) {
                $g->getLogger()->addNotice(sprintf("%s: Removing scene id=%d", self::Module, $id));
                $s = $g->getEntityManager()->getRepository(Scene::class)->find($id);
                $g->getEntityManager()->remove($s);
            }
        }
    }
}
