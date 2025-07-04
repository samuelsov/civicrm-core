<?php
namespace Civi\API\Subscriber;

use Civi\API\Kernel;
use Civi\API\Provider\StaticProvider;
use Civi\Core\CiviEventDispatcher;

/**
 */
class DynamicFKAuthorizationTest extends \CiviUnitTestCase {
  const FILE_WIDGET_ID = 10;

  const FILE_FORBIDDEN_ID = 11;

  const FILE_UNDELEGATED_ENTITY = 12;

  const WIDGET_ID = 20;

  const FORBIDDEN_ID = 30;

  /**
   * @var \Civi\Core\CiviEventDispatcher
   */
  public $dispatcher;

  /**
   * @var \Civi\API\Kernel
   */
  public $kernel;

  protected function setUp(): void {
    parent::setUp();

    $this->hookClass->setHook('civicrm_entityTypes', function (array &$entityTypes) {
      $entityTypes['FakeFile'] = [
        'name' => 'FakeFile',
        'class' => 'CRM_Fake_DAO_FakeFile',
        'table' => 'fake_file',
      ];
      $entityTypes['Widget'] = [
        'name' => 'Widget',
        'class' => 'CRM_Fake_DAO_Widget',
        'table' => 'fake_widget',
      ];
      $entityTypes['Forbidden'] = [
        'name' => 'Forbidden',
        'class' => 'CRM_Fake_DAO_Forbidden',
        'table' => 'fake_forbidden',
      ];
    });
    \CRM_Core_DAO_AllCoreTables::flush();

    $fileProvider = new StaticProvider(
      3,
      'FakeFile',
      ['id', 'entity_table', 'entity_id'],
      [],
      [
        ['id' => self::FILE_WIDGET_ID, 'entity_table' => 'fake_widget', 'entity_id' => self::WIDGET_ID],
        ['id' => self::FILE_FORBIDDEN_ID, 'entity_table' => 'fake_forbidden', 'entity_id' => self::FORBIDDEN_ID],
      ]
    );

    $widgetProvider = new StaticProvider(3, 'Widget',
      ['id', 'title'],
      [],
      [
        ['id' => self::WIDGET_ID, 'title' => 'my widget'],
      ]
    );

    $forbiddenProvider = new StaticProvider(
      3,
      'Forbidden',
      ['id', 'label'],
      [
        'create' => \CRM_Core_Permission::ALWAYS_DENY_PERMISSION,
        'get' => \CRM_Core_Permission::ALWAYS_DENY_PERMISSION,
        'delete' => \CRM_Core_Permission::ALWAYS_DENY_PERMISSION,
      ],
      [
        ['id' => self::FORBIDDEN_ID, 'label' => 'my forbidden'],
      ]
    );

    $this->dispatcher = new CiviEventDispatcher();
    $this->kernel = new Kernel($this->dispatcher);
    $this->kernel
      ->registerApiProvider($fileProvider)
      ->registerApiProvider($widgetProvider)
      ->registerApiProvider($forbiddenProvider);
    $this->dispatcher->addSubscriber(new DynamicFKAuthorization(
      $this->kernel,
      'FakeFile',
      ['create', 'get'],
      // Given a file ID, determine the entity+table it's attached to.
      "select
      case %1
        when " . self::FILE_WIDGET_ID . " then 1
        when " . self::FILE_FORBIDDEN_ID . " then 1
        else 0
      end as is_valid,
      case %1
        when " . self::FILE_WIDGET_ID . " then 'fake_widget'
        when " . self::FILE_FORBIDDEN_ID . " then 'fake_forbidden'
        else null
      end as entity_table,
      case %1
        when " . self::FILE_WIDGET_ID . ' then ' . self::WIDGET_ID . '
        when ' . self::FILE_FORBIDDEN_ID . ' then ' . self::FORBIDDEN_ID . '
        else null
      end as entity_id
      ',
      // Get a list of custom fields (field_name,table_name,extends)
      'select',
      ['fake_widget', 'fake_forbidden']
    ));
  }

  /**
   * @return array
   */
  public static function okDataProvider() {
    $cases = [];

    $cases[] = ['Widget', 'create', ['id' => self::WIDGET_ID]];
    $cases[] = ['Widget', 'get', ['id' => self::WIDGET_ID]];

    $cases[] = ['FakeFile', 'create', ['id' => self::FILE_WIDGET_ID]];
    $cases[] = ['FakeFile', 'get', ['id' => self::FILE_WIDGET_ID]];
    $cases[] = [
      'FakeFile',
      'create',
      ['entity_table' => 'fake_widget', 'entity_id' => self::WIDGET_ID],
    ];

    return $cases;
  }

  /**
   * @return array
   */
  public static function badDataProvider() {
    $cases = [];

    $cases[] = ['Forbidden', 'create', ['id' => self::FORBIDDEN_ID], '/Authorization failed/'];
    $cases[] = ['Forbidden', 'get', ['id' => self::FORBIDDEN_ID], '/Authorization failed/'];

    $cases[] = ['FakeFile', 'create', ['id' => self::FILE_FORBIDDEN_ID], '/Authorization failed/'];
    $cases[] = ['FakeFile', 'get', ['id' => self::FILE_FORBIDDEN_ID], '/Authorization failed/'];

    $cases[] = ['FakeFile', 'create', ['entity_table' => 'fake_forbidden'], '/Authorization failed/'];
    $cases[] = ['FakeFile', 'get', ['entity_table' => 'fake_forbidden'], '/Authorization failed/'];

    $cases[] = [
      'FakeFile',
      'create',
      ['entity_table' => 'fake_forbidden', 'entity_id' => self::FORBIDDEN_ID],
      '/Authorization failed/',
    ];
    $cases[] = [
      'FakeFile',
      'get',
      ['entity_table' => 'fake_forbidden', 'entity_id' => self::FORBIDDEN_ID],
      '/Authorization failed/',
    ];

    $cases[] = [
      'FakeFile',
      'create',
      [],
      "/Mandatory key\\(s\\) missing from params array: 'id' or 'entity_table/",
    ];
    $cases[] = [
      'FakeFile',
      'get',
      [],
      "/Mandatory key\\(s\\) missing from params array: 'id' or 'entity_table/",
    ];

    $cases[] = ['FakeFile', 'create', ['entity_table' => 'unknown'], '/Unrecognized target entity/'];
    $cases[] = ['FakeFile', 'get', ['entity_table' => 'unknown'], '/Unrecognized target entity/'];

    // We should be allowed to lookup files for fake_widgets, but we need an ID.
    $cases[] = ['FakeFile', 'get', ['entity_table' => 'fake_widget'], '/Missing entity_id/'];

    return $cases;
  }

  /**
   * @param string $entity
   * @param string $action
   * @param array $params
   *
   * @dataProvider okDataProvider
   */
  public function testOk(string $entity, string $action, array $params): void {
    $params['version'] = 3;
    $params['debug'] = 1;
    $params['check_permissions'] = 1;
    $result = $this->kernel->runSafe($entity, $action, $params);
    $this->assertFalse((bool) $result['is_error'], print_r([
      '$entity' => $entity,
      '$action' => $action,
      '$params' => $params,
      '$result' => $result,
    ], TRUE));
  }

  /**
   * @param string $entity
   * @param int $action
   * @param array $params
   * @param array $expectedError
   * @dataProvider badDataProvider
   */
  public function testBad($entity, $action, $params, $expectedError) {
    $params['version'] = 3;
    $params['debug'] = 1;
    $params['check_permissions'] = 1;
    $result = $this->kernel->runSafe($entity, $action, $params);
    $this->assertTrue((bool) $result['is_error'], print_r([
      '$entity' => $entity,
      '$action' => $action,
      '$params' => $params,
      '$result' => $result,
    ], TRUE));
    $this->assertMatchesRegularExpression($expectedError, $result['error_message']);
  }

  /**
   * Test whether trusted API calls bypass the permission check
   *
   */
  public function testNotDelegated(): void {
    $entity = 'FakeFile';
    $action = 'create';
    $params = [
      'entity_id' => self::FILE_UNDELEGATED_ENTITY,
      'entity_table' => 'civicrm_membership',
      'version' => 3,
      'debug' => 1,
      'check_permissions' => 1,
    ];
    // run with permission check
    $result = $this->kernel->runSafe('FakeFile', 'create', $params);
    $this->assertTrue((bool) $result['is_error'], 'Undelegated entity with check_permissions = 1 should fail');
    $this->assertMatchesRegularExpression('/Unrecognized target entity table \(civicrm_membership\)/', $result['error_message']);
    // repeat without permission check
    $params['check_permissions'] = 0;
    $result = $this->kernel->runSafe('FakeFile', 'create', $params);
    $this->assertFalse((bool) $result['is_error'], 'Undelegated entity with check_permissions = 0 should succeed');
  }

}
