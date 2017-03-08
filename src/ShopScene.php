<?php
declare(strict_types=1);

namespace LotGD\Modules\ArmorShop;

use LotGD\Core\Action;
use LotGD\Core\ActionGroup;
use LotGD\Core\Game;
use LotGD\Core\Models\Scene;
use LotGD\Core\Models\Viewpoint;
use LotGD\Modules\Forms\ {
    Form,
    FormElement,
    FormElementOptions,
    FormElementType
};
use LotGD\Modules\SimpleInventory\Module as SimpleInventory;
use LotGD\Modules\SimpleWealth\Module as SimpleWealth;

class ShopScene
{
    public static function getScene()
    {
        return Scene::create([
            'template' => Module::ArmorShopScene,
            'title' => 'Pegasus\'s Armor',
            'description' => "`5The fair and beautiful `#Pegasus`5 greets you with a warm smile "
                           . "as you stroll over to her brightly colored gypsy wagon, which is placed, "
                           . "not out of coincidence, right next to `!MightyE`5's armor shop.\n"
                           . "Her outfit is as brightly colored and outrageous as her wagon, "
                           . "and it is almost (but not quite) enough to make you look away from her huge"
                           . "gray eyes and flashes of skin between her not-quite-sufficient gypsy clothes. ",

        ]);
    }

    private static function getArmor(Game $g)
    {
        $inventory = new SimpleInventory($g);
        $armor = $inventory->getArmorForUser($g->getCharacter());
        return $armor;
    }

    private static function getTradeInValue(Game $g): int
    {
        $armor = self::getArmor($g);

        // Get the trade-in value for their existing armor.
        if (!$armor) {
            $u_id = $user->getId();
            $g->getLogger()->error("Couldn't find a armor for user {$u_id}.");
            return 0;
        } else if ($armor) {
            $context = [
                'value' => Module::tradeInValue($armor),
                'armor' => $armor
            ];
            $g->getEventManager()->publish(Module::TradeInHook, $context);

            return $context['value'];
        }
    }

    private static function addTradeInMessage(Game $g, Scene $scene, Viewpoint $viewpoint)
    {
        $value = self::getTradeInValue($g);

        if ($value > 0) {
            $armor = self::getArmor($g);
            $name = $armor->getName();
            $description = $scene->getDescription();
            $description .= "\n`!Pegasus`7 looks at you and says, \"`#I'll give you `^{$value}`# trade-in value for your `5{$name}`#.";
            $viewpoint->setDescription($description);
        }
    }

    private static function getBuyAction(Game $g, Scene $scene): Action
    {
        // Find the child w/ the right template.
        foreach ($scene->getChildren() as $child) {
            if ($child->getTemplate() === Module::ArmorShopBuyScene) {
                return new Action($child->getId());
            }
        }
        $id = $scene->getId();
        throw new Exception("Can't find a buy scene that's a child of scene id={$id}");
    }

    private static function addForSaleForm(Game $g, Scene $scene, Viewpoint $viewpoint, int $tradeInValue)
    {
        $user = $viewpoint->getOwner();

        $wealth = new SimpleWealth($g);
        $gold = $wealth->getGoldForUser($user);

        $armor = self::getArmor($g);

        $inventory = new SimpleInventory($g);
        $armors = $inventory->getArmorsForLevel($user->getLevel());

        $elements = [];
        foreach ($armors as $a) {
            $id = $a->getId();
            $name = $a->getName();
            // Disable armor that are too expensive or that you already own.
            $options = ($a->getCost() - $tradeInValue <= $gold) && ($id != $armor->getId())
                ? FormElementOptions::None()
                : FormElementOptions::Disabled();

            $elements[] = new FormElement(Module::ChoiceParameter,
                                          FormElementType::Button(),
                                          "{$name}",
                                          $id,
                                          $options);
        }

        $buyAction = self::getBuyAction($g, $scene);
        $form = new Form($elements, $buyAction);

        $attachments = $viewpoint->getAttachments();
        $attachments[] = $form;
        $viewpoint->setAttachments($attachments);

        // The buy action must be present in the viewpoint for a user to take it,
        // but we don't want it shown in the menu. The client can choose to display
        // a submit button if they like.
        $viewpoint->addActionToGroupId($buyAction, ActionGroup::HiddenGroup);
    }

    private static function addMenu(Game $g, Scene $scene, Viewpoint $viewpoint)
    {
        $viewpoint->addActionToGroupId(new Action($scene->getParents()[0]->getId()), ActionGroup::DefaultGroup);
    }

    public static function handleViewpoint(Game $g, array $context)
    {
        // Prepare the armor shop viewpoint with the current trade
        // in value, if any, and the list of armor for the current
        // user's level.

        $scene = $context['scene'];
        $viewpoint = $context['viewpoint'];

        self::addTradeInMessage($g, $scene, $viewpoint);
        self::addForSaleForm($g, $scene, $viewpoint, self::getTradeInValue($g));
        self::addMenu($g, $scene, $viewpoint);

        $viewpoint->save($g->getEntityManager());
    }
}
