<?php
declare(strict_types=1);

namespace LotGD\Modules\ArmorShop;

use LotGD\Core\Action;
use LotGD\Core\ActionGroup;
use LotGD\Core\Game;
use LotGD\Core\Models\Viewpoint;
use LotGD\Core\Models\Scene;
use LotGD\Modules\SimpleInventory\Module as SimpleInventory;
use LotGD\Modules\SimpleWealth\Module as SimpleWealth;

class BuyScene
{
    public static function getScene()
    {
        return Scene::create([
            'template' => Module::ArmorShopBuyScene,
            'title' => 'Pegasus\'s Armor',
            'description' => ''
        ]);
    }

    private static function getChoiceArmor(Game $g, array $parameters)
    {
        if (isset($parameters[Module::ChoiceParameter])) {
            $inventory = new SimpleInventory($g);
            $id = $parameters[Module::ChoiceParameter];
            return $inventory->getArmorById($id);
        } else {
            return null;
        }
    }

    private static function addDescription(Game $g, Scene $scene, Viewpoint $viewpoint, array $parameters)
    {
        $user = $viewpoint->getOwner();
        $description = $viewpoint->getDescription();

        $choiceArmor = self::getChoiceArmor($g, $parameters);

        $inventory = new SimpleInventory($g);
        $currentArmor = $inventory->getArmorForUser($user);
        $tradeInValue = Module::tradeInValue($currentArmor);

        $wealth = new SimpleWealth($g);
        $gold = $wealth->getGoldForUser($user);

        if ($choiceArmor === null) {
            $description .= "`!Pegasus`7 looks at you, confused for a second, then realizes that you've apparently "
                ."taken one too many bonks on the head, and nods and smiles.";
        } else {
            $newArmorName = $choiceArmor->getName();
            if ($gold + $tradeInValue < $choiceArmor->getCost()) {
                $description .= "Waiting until `!Pegasus`7 looks away, you reach carefully for the `5{$newArmorName}`7, "
                    ."which you silently remove from the stack of clothes upon which it sits. Secure in your theft, you turn around "
                    ."only to realize that your turning action is hindered by a fist closed tightly around your throat. "
                    ."Glancing down, you trace the fist to the arm on which it is attached, which in turn is attached to a very muscular `!MightyE`5. "
                    ."You try to explain what happened here, but your throat doesn't seem to be able to open up to let your voice through, "
                    ."let alone essential oxygen. As darkness creeps in on the edge of your vision, you glance pleadingly, "
                    ."but futilely at `!Pegasus`7 who is staring dreamily at `!MightyE`5, her hands clutched next to her face, "
                    ."which is painted with a large admiring smile. You wake up some time later, having been tossed unconscious into the street. ";
            } else {
                $currentArmorName = $currentArmor->getName();
                $wealth->setGoldForUser($user, $gold - ($choiceArmor->getCost() - $tradeInValue));
                $inventory->setArmorForUser($user, $choiceArmor);
                $user->save($g->getEntityManager());

                $description .= "`!Pegasus`7 takes your `5{$currentArmorName}`7 and promptly puts a price on it, "
                    . "putting it on display with the rest of his armor.`n`nIn return, he hands you a shiny "
                    . "new `5{$newArmorName}`7. You begin to protest, \"`@Won't I look silly wearing nothing but my `&%s`@?`5\" you ask. "
                    . "You ponder it a moment, and then realize that everyone else in the town is doing the same thing. \"`@Oh well, when in Rome...`5";
            }
        }
        $viewpoint->setDescription($description);
    }

    private static function addMenu(Game $g, Viewpoint $viewpoint, array $context)
    {
        // Add the back action to the scene before the shop, passed down as 'referrer' in
        // the context.
        $referrer = $context['referrer'];
        $viewpoint->addActionToGroupId(new Action($referrer->getId()), ActionGroup::DefaultGroup);
    }

    public static function handleViewpoint(Game $g, array $context)
    {
        $em = $g->getEntityManager();

        $scene = $context['scene'];
        $viewpoint = $context['viewpoint'];
        $parameters = $context['parameters'];

        self::addDescription($g, $scene, $viewpoint, $parameters);
        self::addMenu($g, $viewpoint, $context);

        $viewpoint->save($em);
    }
}
