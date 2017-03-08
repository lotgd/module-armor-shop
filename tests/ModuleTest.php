<?php
declare(strict_types=1);

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

use LotGD\Core\Configuration;
use LotGD\Core\Game;
use LotGD\Core\EventHandler;
use LotGD\Core\LibraryConfigurationManager;
use LotGD\Core\Models\Character;
use LotGD\Core\Models\Module as ModuleModel;
use LotGD\Core\Models\ModuleProperty;
use LotGD\Core\Models\Scene;
use LotGD\Core\Tests\ModelTestCase;

use LotGD\Modules\SimpleInventory\Module as SimpleInventory;
use LotGD\Modules\SimpleInventory\Models\Armor;
use LotGD\Modules\Forms\FormElementOptions;

use LotGD\Modules\ArmorShop\Module;

class DefaultSceneProvider implements EventHandler
{
    public static function handleEvent(Game $g, string $event, array &$context)
    {
        switch ($event) {
            case 'h/lotgd/core/default-scene':
                $context['scene'] = $g->getEntityManager()->getRepository(Scene::class)->find(1);
                break;
        }
    }
}

class ModuleTest extends ModelTestCase
{
    const Library = 'lotgd/module-armor-shop';

    private $libraryConfiguration;
    private $g;
    private $moduleModel;

    protected function getDataSet(): \PHPUnit_Extensions_Database_DataSet_YamlDataSet
    {
        return new \PHPUnit_Extensions_Database_DataSet_YamlDataSet(implode(DIRECTORY_SEPARATOR, [__DIR__, 'datasets', 'module.yml']));
    }

    public function setUp()
    {
        parent::setUp();

        $logger  = new Logger('test');
        $logger->pushHandler(new RotatingFileHandler(__DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'lotgd', 14));
        $name = $this->getName();
        $logger->addDebug("{$name}: setUp()");

        // Create a Game object for use in these tests.
        $this->g = new Game(new Configuration(getenv('LOTGD_TESTS_CONFIG_PATH')), $logger, $this->getEntityManager(), implode(DIRECTORY_SEPARATOR, [__DIR__, '..']));

        // Register and unregister before/after each test, since
        // handleEvent() calls may expect the module be registered (for example,
        // if they read properties from the model).

        $libraryConfigurationManager = new LibraryConfigurationManager($this->g->getComposerManager(), __DIR__ . DIRECTORY_SEPARATOR . '..');
        $this->libraryConfiguration = $libraryConfigurationManager->getConfigurationForLibrary(self::Library);
        $this->g->getModuleManager()->register($this->libraryConfiguration);
    }

    public function navigationSetUpAndAssert(Character $character)
    {
        $village = $this->getEntityManager()->getRepository(Scene::class)->find(1);

        $this->g->setCharacter($character);

        // Set up a viewpoint to be in the dummy village, via the default viewpoint
        // functionality.
        $this->g->getEventManager()->subscribe('/h\/lotgd\/core\/default-scene/', DefaultSceneProvider::class, 'lotgd/core/tests');
        $viewpoint = $this->g->getViewpoint();
        $this->assertSame($village->getTemplate(), $viewpoint->getTemplate());

        // Navigate to the first action, which should be the armor shop.
        $this->g->takeAction($viewpoint->getActionGroups()[0]->getActions()[0]->getId());
        $viewpoint = $this->g->getViewpoint();
        $this->assertSame(Module::ArmorShopScene, $viewpoint->getTemplate());
    }

    public function navigationTearDown()
    {
        $this->g->getEventManager()->unsubscribe('/h\/lotgd\/core\/default-scene/', DefaultSceneProvider::class, 'lotgd/core/tests');
    }

    public function tearDown()
    {
        $name = $this->getName();
        $this->g->getLogger()->addDebug("{$name}: tearDown()");
        $this->g->getModuleManager()->unregister($this->libraryConfiguration);

        parent::tearDown();
    }

    public function testWasAddedAsChildToVillage()
    {
        $village = $this->getEntityManager()->getRepository(Scene::class)->find(1);
        $this->assertSame(1, count($village->getChildren()));
        $shop = $village->getChildren()[0];
        $this->assertSame(Module::ArmorShopScene, $shop->getTemplate());
        $this->assertSame($village->getId(), $shop->getParents()[0]->getId());
    }

    public function testHandleUnknownEvent()
    {
        // Always good to test a non-existing event just to make sure nothing happens :).
        $context = [];
        Module::handleEvent($this->g, 'e/lotgd/tests/unknown-event', $context);
    }

    public function testHandleVillageEvent()
    {
        $character = $this->g->getEntityManager()->getRepository(Character::class)->find(1);
        $scene = $this->g->getEntityManager()->getRepository(Scene::class)->findOneBy(["template" => "lotgd/module-armor-shop/shop"]);

        $context = [
            "character" => $character,
            "scene" => $scene,
            "viewpoint" =>  $character->getViewpoint(),
        ];

        Module::handleEvent($this->g, 'e/lotgd/core/navigate-to/lotgd/module-armor-shop/shop', $context);
    }

    public function testNavigateToShopNoArmors()
    {
        $inventory = new SimpleInventory($this->g);

        $character = $this->getEntityManager()->getRepository(Character::class)->find(0); // Thorin is level 50, no armor at that level in our sample DB.
        $armor = $this->getEntityManager()->getRepository(Armor::class)->find(1);
        $inventory->setArmorForUser($character, $armor);

        $this->navigationSetUpAndAssert($character);
        $viewpoint = $this->g->getViewpoint();

        // Check the trade in value.
        $this->assertStringMatchesFormat('%A`^75`# trade-in value%A', $viewpoint->getDescription());

        // No attachments since there are no armor.
        $this->assertEmpty($viewpoint->getAttachments()[0]->getElements());

        $this->navigationTearDown();
    }

    public function testNavigateToShop()
    {
        $inventory = new SimpleInventory($this->g);

        $character = $this->getEntityManager()->getRepository(Character::class)->find(1);
        $armor = $this->getEntityManager()->getRepository(Armor::class)->find(1);
        $inventory->setArmorForUser($character, $armor);

        $this->navigationSetUpAndAssert($character);
        $viewpoint = $this->g->getViewpoint();

        // Check the trade in value.
        $this->assertStringMatchesFormat('%A`^75`# trade-in value%A', $viewpoint->getDescription());

        // One attachment, one armor, id=1, disabled since you cant buy the same one you have.
        $this->assertSame(1, $viewpoint->getAttachments()[0]->getElements()[0]->getValue());
        $this->assertTrue($viewpoint->getAttachments()[0]->getElements()[0]->getOptions()->get(FormElementOptions::Disabled));

        $this->navigationTearDown();
    }

    public function testBuy()
    {
        $inventory = new SimpleInventory($this->g);

        $character = $this->getEntityManager()->getRepository(Character::class)->find(2);
        $armor = $this->getEntityManager()->getRepository(Armor::class)->find(1);
        $inventory->setArmorForUser($character, $armor);

        $this->navigationSetUpAndAssert($character);
        $viewpoint = $this->g->getViewpoint();

        // One attachment, two armor.
        $this->assertSame(2, count($viewpoint->getAttachments()[0]->getElements()));
        // Second armor is too expensive (more than the trade in value of Armor id=1)
        $this->assertFalse($viewpoint->getAttachments()[0]->getElements()[0]->getOptions()->get(FormElementOptions::Disabled));
        $this->assertTrue($viewpoint->getAttachments()[0]->getElements()[1]->getOptions()->get(FormElementOptions::Disabled));

        $this->g->takeAction($viewpoint->getAttachments()[0]->getAction()->getId(), ['choice' => 2]);
        $viewpoint = $this->g->getViewpoint();
        $this->assertSame(Module::ArmorShopBuyScene, $viewpoint->getTemplate());
        $this->assertSame(2, $inventory->getArmorForUser($character)->getId());

        $this->navigationTearDown();
    }

    public function testBuyTooExpensive()
    {
        $inventory = new SimpleInventory($this->g);

        $character = $this->getEntityManager()->getRepository(Character::class)->find(3);
        $armor = $this->getEntityManager()->getRepository(Armor::class)->find(1);
        $inventory->setArmorForUser($character, $armor);

        $this->navigationSetUpAndAssert($character);
        $viewpoint = $this->g->getViewpoint();

        // One attachment, two armor.
        $this->assertSame(2, count($viewpoint->getAttachments()[0]->getElements()));
        // Second armor is too expensive (more than the trade in value of Armor id=1)
        $this->assertFalse($viewpoint->getAttachments()[0]->getElements()[0]->getOptions()->get(FormElementOptions::Disabled));
        $this->assertTrue($viewpoint->getAttachments()[0]->getElements()[1]->getOptions()->get(FormElementOptions::Disabled));

        $this->g->takeAction($viewpoint->getAttachments()[0]->getAction()->getId(), ['choice' => 3]);
        $viewpoint = $this->g->getViewpoint();
        $this->assertSame(Module::ArmorShopBuyScene, $viewpoint->getTemplate());
        $this->assertSame(1, $inventory->getArmorForUser($character)->getId());

        $this->navigationTearDown();
    }
}
