<?php

namespace Oro\Bundle\NavigationBundle\Tests\Functional\Manager;

use Doctrine\ORM\EntityRepository;
use Knp\Menu\ItemInterface;
use Oro\Bundle\NavigationBundle\Entity\MenuUpdate;
use Oro\Bundle\NavigationBundle\Entity\Repository\MenuUpdateRepository;
use Oro\Bundle\NavigationBundle\Manager\MenuUpdateManager;
use Oro\Bundle\NavigationBundle\Tests\Functional\DataFixtures\MenuUpdateData;
use Oro\Bundle\NavigationBundle\Utils\MenuUpdateUtils;
use Oro\Bundle\ScopeBundle\Entity\Scope;
use Oro\Bundle\ScopeBundle\Manager\ScopeManager;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\UIBundle\Model\TreeItem;

class MenuUpdateManagerTest extends WebTestCase
{
    private const MENU_NAME = 'application_menu';

    /** @var EntityRepository */
    private $repository;

    /** @var MenuUpdateManager */
    private $manager;

    protected function setUp(): void
    {
        $this->initClient([], $this->generateBasicAuthHeader());
        $this->loadFixtures([MenuUpdateData::class]);

        $this->manager = $this->getContainer()->get('oro_navigation.manager.menu_update');
        $this->repository = self::getContainer()->get('doctrine')->getRepository(MenuUpdate::class);
    }

    public function testCreateMenuUpdate()
    {
        $scope = $this->getScope();
        $actualMenuUpdate = $this->manager->createMenuUpdate(
            $this->getMenu(),
            [
                'key' => 'unique_item_key',
                'custom' => true,
                'menu' => 'application_menu',
                'scope' => $scope,
                'parentKey' => null,
                'isDivider' => false
            ]
        );
        $expectedMenuUpdate = new MenuUpdate();
        $expectedMenuUpdate->setKey('unique_item_key')
            ->setCustom(true)
            ->setMenu('application_menu')
            ->setParentKey(null)
            ->setDivider(false)
            ->setScope($scope);
        $this->assertEquals($expectedMenuUpdate, $actualMenuUpdate);
    }

    public function testUpdateMenuUpdate()
    {
        $menu = $this->getMenu();
        $item = MenuUpdateUtils::findMenuItem($menu, 'oro_organization_list');
        $update = new MenuUpdate();
        $update->setKey('oro_organization_list')
            ->setParentKey('dashboard_tab');

        $this->manager->updateMenuUpdate($update, $item, 'menu');
        $this->assertEquals('oro_organization_list', $update->getKey());
        $this->assertEquals('dashboard_tab', $update->getParentKey());
    }

    public function testFindOrCreateMenuUpdate()
    {
        $scope = $this->getScope();
        $expectedMenuUpdate = new MenuUpdate();
        $expectedMenuUpdate->setKey('unique_item_key')
            ->setCustom(true)
            ->setMenu('application_menu')
            ->setParentKey(null)
            ->setScope($scope)
            ->setDivider(false);

        $actualMenuUpdate = $this->manager->findOrCreateMenuUpdate($this->getMenu(), 'unique_item_key', $scope);
        $this->assertEquals($expectedMenuUpdate, $actualMenuUpdate);
    }

    public function testShowMenuItem()
    {
        $scope = $this->getScope();
        $this->manager->showMenuItem($this->getMenu(), MenuUpdateData::MENU_UPDATE_2_1, $scope);

        /** @var MenuUpdate[] $result */
        $result = $this->repository->findBy([
            'menu'  => self::MENU_NAME,
            'key'   => [
                MenuUpdateData::MENU_UPDATE_2,
                MenuUpdateData::MENU_UPDATE_2_1,
                MenuUpdateData::MENU_UPDATE_2_1_1
            ],
            'scope' => $scope,
        ]);

        foreach ($result as $entity) {
            $this->assertTrue($entity->isActive());
        }
    }

    public function testHideMenuItem()
    {
        $scope = $this->getScope();
        $this->manager->hideMenuItem($this->getMenu(), MenuUpdateData::MENU_UPDATE_1, $scope);

        /** @var MenuUpdate[] $result */
        $result = $this->repository->findBy([
            'menu'  => self::MENU_NAME,
            'key'   => [MenuUpdateData::MENU_UPDATE_1, MenuUpdateData::MENU_UPDATE_1_1],
            'scope' => $scope
        ]);

        foreach ($result as $entity) {
            $this->assertFalse($entity->isActive());
        }
    }

    public function testMoveMenuItem()
    {
        $updates = $this->manager->moveMenuItem(
            $this->getMenu(),
            MenuUpdateData::MENU_UPDATE_3_1,
            $this->getScope(),
            MenuUpdateData::MENU_UPDATE_2,
            0
        );

        $this->assertCount(2, $updates);

        $this->assertEquals(0, $updates[0]->getPriority());
        $this->assertEquals(MenuUpdateData::MENU_UPDATE_3_1, $updates[0]->getKey());
        $this->assertEquals(MenuUpdateData::MENU_UPDATE_2, $updates[0]->getParentKey());

        $this->assertEquals(MenuUpdateData::MENU_UPDATE_2_1, $updates[1]->getKey());
        $this->assertEquals(1, $updates[1]->getPriority());
    }

    public function testMoveMenuItems()
    {
        $updates = $this->manager->moveMenuItems(
            $this->getMenu(),
            [
                new TreeItem(MenuUpdateData::MENU_UPDATE_3_1),
                new TreeItem(MenuUpdateData::MENU_UPDATE_3)
            ],
            $this->getScope(),
            MenuUpdateData::MENU_UPDATE_2,
            0
        );

        $this->assertCount(3, $updates);

        $this->assertEquals(MenuUpdateData::MENU_UPDATE_3_1, $updates[0]->getKey());
        $this->assertEquals(MenuUpdateData::MENU_UPDATE_3, $updates[1]->getKey());
        $this->assertEquals(MenuUpdateData::MENU_UPDATE_2_1, $updates[2]->getKey());

        $this->assertEquals(MenuUpdateData::MENU_UPDATE_2, $updates[0]->getParentKey());
        $this->assertEquals(MenuUpdateData::MENU_UPDATE_2, $updates[1]->getParentKey());
        $this->assertEquals(MenuUpdateData::MENU_UPDATE_2, $updates[2]->getParentKey());

        $this->assertEquals(0, $updates[0]->getPriority());
        $this->assertEquals(1, $updates[1]->getPriority());
        $this->assertEquals(2, $updates[2]->getPriority());
    }

    public function testDeleteMenuUpdates()
    {
        $scope = $this->getScope();
        $this->manager->deleteMenuUpdates($scope, self::MENU_NAME);

        /** @var MenuUpdate[] $result */
        $result = $this->repository->findBy(['menu' => self::MENU_NAME, 'scope' => $scope]);

        $this->assertCount(0, $result);
    }

    public function testGetRepository()
    {
        $repository = $this->manager->getRepository();
        $this->assertInstanceOf(MenuUpdateRepository::class, $repository);
    }

    private function getScope(): Scope
    {
        /** @var ScopeManager $scopeManager */
        $scopeManager = $this->getContainer()->get('oro_scope.scope_manager');

        return $scopeManager->findOrCreate('menu_default_visibility', []);
    }

    private function getMenu(): ItemInterface
    {
        return $this->getContainer()->get('oro_menu.builder_chain')->get(self::MENU_NAME);
    }
}
