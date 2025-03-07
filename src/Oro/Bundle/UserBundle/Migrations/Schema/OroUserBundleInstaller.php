<?php

namespace Oro\Bundle\UserBundle\Migrations\Schema;

use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\ActivityBundle\Migration\Extension\ActivityExtension;
use Oro\Bundle\ActivityBundle\Migration\Extension\ActivityExtensionAwareInterface;
use Oro\Bundle\AttachmentBundle\Migration\Extension\AttachmentExtensionAwareInterface;
use Oro\Bundle\AttachmentBundle\Migration\Extension\AttachmentExtensionAwareTrait;
use Oro\Bundle\EntityBundle\EntityConfig\DatagridScope;
use Oro\Bundle\EntityExtendBundle\EntityConfig\ExtendScope;
use Oro\Bundle\EntityExtendBundle\Extend\RelationType;
use Oro\Bundle\EntityExtendBundle\Migration\Extension\ExtendExtension;
use Oro\Bundle\EntityExtendBundle\Migration\Extension\ExtendExtensionAwareInterface;
use Oro\Bundle\FormBundle\Form\Type\OroResizeableRichTextType;
use Oro\Bundle\MigrationBundle\Migration\Extension\DatabasePlatformAwareInterface;
use Oro\Bundle\MigrationBundle\Migration\Extension\DatabasePlatformAwareTrait;
use Oro\Bundle\MigrationBundle\Migration\Installation;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;
use Oro\Bundle\MigrationBundle\Migration\SqlMigrationQuery;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_0\OroUserBundle;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_10\OroUserBundle as PasswordChanged;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_15\RemoveOldSchema;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_15\SetOwnerForEmail;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_15\UpdateEmailOriginRelation as EmailOrigin;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_16\AddRelationToMailbox;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_18\ChangeEmailUserFolderRelation as ChangeEmailUserFolderRelation;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_18\DropEmailUserColumn;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_19\AddFirstNameLastNameIndex;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_2\OroUserBundle as UserAvatars;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_22\AddImpersonationTable;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_24\AddAuthStatusColumn;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_24\AddImpersonationIpColumn;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_3\OroUserBundle as UserEmailActivities;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_4\AttachmentOwner;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_5\SetOwnerForEmailTemplates as EmailTemplateOwner;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_7\OroUserBundle as UserOrganization;
use Oro\Bundle\UserBundle\Migrations\Schema\v1_9\OroUserBundle as ExtendTitle;
use Oro\Bundle\UserBundle\Migrations\Schema\v2_0\OroUserBundle as OroUserBundle20;
use Oro\Bundle\UserBundle\Migrations\Schema\v2_9\SetOwnerForEmailOrganizationOnDeleteCascade;

/**
 * ORO installer for UserBundle
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class OroUserBundleInstaller implements
    Installation,
    DatabasePlatformAwareInterface,
    AttachmentExtensionAwareInterface,
    ExtendExtensionAwareInterface,
    ActivityExtensionAwareInterface
{
    use DatabasePlatformAwareTrait;
    use AttachmentExtensionAwareTrait;

    /** @var ActivityExtension */
    protected $activityExtension;

    /** @var ExtendExtension */
    protected $extendExtension;

    /**
     * {@inheritdoc}
     */
    public function getMigrationVersion()
    {
        return 'v2_9';
    }

    /**
     * {@inheritdoc}
     */
    public function setActivityExtension(ActivityExtension $activityExtension)
    {
        $this->activityExtension = $activityExtension;
    }

    /**
     * {@inheritdoc}
     */
    public function setExtendExtension(ExtendExtension $extendExtension)
    {
        $this->extendExtension = $extendExtension;
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema, QueryBag $queries)
    {
        /** Tables generation **/
        $this->createOroUserEmailTable($schema, $queries);
        $this->createOroUserApiTable($schema);

        $this->createOroUserTable($schema);
        UserAvatars::addAvatarToUser($schema, $this->attachmentExtension);
        UserAvatars::addOwnerToOroFile($schema);
        AttachmentOwner::addOwnerToAttachment($schema);

        $this->createOroUserAccessRoleTable($schema);
        $this->createOroUserAccessGroupTable($schema);
        $this->createOroUserBusinessUnitTable($schema);
        $this->createOroAccessGroupTable($schema);
        $this->createOroUserAccessGroupRoleTable($schema);
        $this->createOroAccessRoleTable($schema);
        $this->createOroUserLoginAttemptsTable($schema);

        /** Foreign keys generation **/
        $this->addOroUserEmailForeignKeys($schema);
        $this->addOroUserApiForeignKeys($schema);
        $this->addOroUserForeignKeys($schema);
        $this->addOroUserAccessRoleForeignKeys($schema);
        $this->addOroUserAccessGroupForeignKeys($schema);
        $this->addOroUserBusinessUnitForeignKeys($schema);
        $this->addOroAccessGroupForeignKeys($schema);
        $this->addOroUserAccessGroupRoleForeignKeys($schema);
        $this->addOroUserLoginAttemptsForeignKeys($schema);

        EmailTemplateOwner::addOwnerToOroEmailTemplate($schema);
        OroUserBundle::addOwnerToOroEmailAddress($schema);
        UserEmailActivities::addActivityAssociations($schema, $this->activityExtension);

        UserOrganization::addOrganizationFields($schema);
        UserOrganization::oroUserOrganizationTable($schema);
        UserOrganization::oroUserOrganizationForeignKeys($schema);

        ExtendTitle::addTitleColumn($schema);
        PasswordChanged::addPasswordChangedColumn($schema);

        $this->addOroAccessGroupIndexes($schema);

        EmailOrigin::addOwnerAndOrganizationColumns($schema);
        SetOwnerForEmail::addOwnerToOroEmail($schema);
        RemoveOldSchema::execute($schema);

        AddRelationToMailbox::createOroEmailMailboxUsersTable($schema);
        AddRelationToMailbox::createOroEmailMailboxRolesTable($schema);
        AddRelationToMailbox::addOroEmailMailboxUsersAndRolesForeignKeys($schema);

        // depends to the UserBundle
        ChangeEmailUserFolderRelation::createOroEmailUserFoldersTable($schema);
        ChangeEmailUserFolderRelation::addOroEmailUserFoldersForeignKeys($schema);
        ChangeEmailUserFolderRelation::updateOroEmailUserTable($schema);
        $this->updateOroEmailUserTable($schema);
        DropEmailUserColumn::updateOroEmailUserTable($schema);
        AddFirstNameLastNameIndex::addFirstNameLastNameIndex($schema);
        AddImpersonationTable::createOroUserImpersonationTable($schema);
        AddImpersonationIpColumn::addColumn($schema);
        $table = $schema->getTable('oro_user_impersonation');
        $table->addIndex(['ip_address'], 'oro_user_imp_ip');

        AddAuthStatusColumn::addAuthStatusFieldAndValues($schema, $queries, $this->extendExtension);
        OroUserBundle20::addEmailUserIndexes($schema);
        SetOwnerForEmailOrganizationOnDeleteCascade::updateOrganizationConstraint($schema);

        $this->addRelationsToScope($schema);
    }

    /**
     * Create oro_user_email table
     */
    protected function createOroUserEmailTable(Schema $schema, QueryBag $queries)
    {
        $table = $schema->createTable('oro_user_email');
        $table->addColumn('id', 'integer', ['precision' => 0, 'autoincrement' => true]);
        $table->addColumn('user_id', 'integer', ['notnull' => false]);
        $table->addColumn('email', 'string', ['length' => 255, 'precision' => 0]);
        $table->addIndex(['user_id'], 'IDX_8600BE16A76ED395', []);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['email'], 'idx_user_email', []);

        if ($this->platform instanceof PostgreSqlPlatform) {
            $queries->addPostQuery(new SqlMigrationQuery(
                'CREATE INDEX idx_user_email_ci ON oro_user_email (LOWER(email))'
            ));
        }
    }

    /**
     * Create oro_user_api table
     */
    protected function createOroUserApiTable(Schema $schema)
    {
        $table = $schema->createTable('oro_user_api');
        $table->addColumn('id', 'integer', ['precision' => 0, 'autoincrement' => true]);
        $table->addColumn('organization_id', 'integer', ['notnull' => false]);
        $table->addColumn('user_id', 'integer', []);
        $table->addColumn('api_key', 'crypted_string', ['length' => 255]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['api_key'], 'UNIQ_296B6993C912ED9D');
        $table->addIndex(['user_id'], 'IDX_296B6993A76ED395', []);
        $table->addIndex(['organization_id'], 'IDX_296B699332C8A3DE', []);
    }

    /**
     * Create oro_user table
     */
    protected function createOroUserTable(Schema $schema)
    {
        $table = $schema->createTable('oro_user');
        $table->addColumn('id', 'integer', ['precision' => 0, 'autoincrement' => true]);
        $table->addColumn('business_unit_owner_id', 'integer', ['notnull' => false]);
        $table->addColumn('username', 'string', ['length' => 255, 'precision' => 0]);
        $table->addColumn('username_lowercase', 'string', ['length' => 255]);
        $table->addColumn('email', 'string', ['length' => 255, 'precision' => 0]);
        $table->addColumn('email_lowercase', 'string', ['length' => 255]);
        $table->addColumn(
            'phone',
            'string',
            [
                'length'      => 255,
                'oro_options' => [
                    'extend'    => ['is_extend' => true, 'owner' => ExtendScope::OWNER_SYSTEM],
                    'dataaudit' => ['auditable' => true]
                ]
            ]
        );
        $table->addColumn('name_prefix', 'string', ['notnull' => false, 'length' => 255, 'precision' => 0]);
        $table->addColumn('first_name', 'string', ['notnull' => false, 'length' => 255, 'precision' => 0]);
        $table->addColumn('middle_name', 'string', ['notnull' => false, 'length' => 255, 'precision' => 0]);
        $table->addColumn('last_name', 'string', ['notnull' => false, 'length' => 255, 'precision' => 0]);
        $table->addColumn('name_suffix', 'string', ['notnull' => false, 'length' => 255, 'precision' => 0]);
        $table->addColumn('birthday', 'date', ['notnull' => false, 'precision' => 0]);
        $table->addColumn('enabled', 'boolean', ['precision' => 0]);
        $table->addColumn('salt', 'string', ['length' => 255, 'precision' => 0]);
        $table->addColumn('password', 'string', ['length' => 255, 'precision' => 0]);
        $table->addColumn('confirmation_token', 'string', ['notnull' => false, 'length' => 255, 'precision' => 0]);
        $table->addColumn('password_requested', 'datetime', ['notnull' => false, 'precision' => 0]);
        $table->addColumn('last_login', 'datetime', ['notnull' => false, 'precision' => 0]);
        $table->addColumn('login_count', 'integer', ['default' => '0', 'precision' => 0, 'unsigned' => true]);
        $table->addColumn('createdAt', 'datetime', ['precision' => 0]);
        $table->addColumn('updatedAt', 'datetime', ['precision' => 0]);
        $table->addUniqueIndex(['username'], 'UNIQ_F82840BCF85E0677');
        $table->addIndex(['username_lowercase'], 'idx_oro_user_username_lowercase', []);
        $table->addUniqueIndex(['email'], 'UNIQ_F82840BCE7927C74');
        $table->addIndex(['email_lowercase'], 'idx_oro_user_email_lowercase', []);
        $table->addIndex(['phone'], 'oro_idx_user_phone');
        $table->addIndex(['business_unit_owner_id'], 'IDX_F82840BC59294170', []);
        $table->setPrimaryKey(['id']);
    }

    /**
     * Create oro_user_access_role table
     */
    protected function createOroUserAccessRoleTable(Schema $schema)
    {
        $table = $schema->createTable('oro_user_access_role');
        $table->addColumn('user_id', 'integer', []);
        $table->addColumn('role_id', 'integer', []);
        $table->addIndex(['user_id'], 'IDX_290571BEA76ED395', []);
        $table->addIndex(['role_id'], 'IDX_290571BED60322AC', []);
        $table->setPrimaryKey(['user_id', 'role_id']);
    }

    /**
     * Create oro_user_access_group table
     */
    protected function createOroUserAccessGroupTable(Schema $schema)
    {
        $table = $schema->createTable('oro_user_access_group');
        $table->addColumn('user_id', 'integer', []);
        $table->addColumn('group_id', 'integer', []);
        $table->addIndex(['user_id'], 'IDX_EC003EF3A76ED395', []);
        $table->addIndex(['group_id'], 'IDX_EC003EF3FE54D947', []);
        $table->setPrimaryKey(['user_id', 'group_id']);
    }

    /**
     * Create oro_user_business_unit table
     */
    protected function createOroUserBusinessUnitTable(Schema $schema)
    {
        $table = $schema->createTable('oro_user_business_unit');
        $table->addColumn('user_id', 'integer', []);
        $table->addColumn('business_unit_id', 'integer', []);
        $table->addIndex(['user_id'], 'IDX_B190CE8FA76ED395', []);
        $table->addIndex(['business_unit_id'], 'IDX_B190CE8FA58ECB40', []);
        $table->setPrimaryKey(['user_id', 'business_unit_id']);
    }

    /**
     * Create oro_access_group table
     */
    protected function createOroAccessGroupTable(Schema $schema)
    {
        $table = $schema->createTable('oro_access_group');
        $table->addColumn('id', 'integer', ['precision' => 0, 'autoincrement' => true]);
        $table->addColumn('business_unit_owner_id', 'integer', ['notnull' => false]);
        $table->addColumn('name', 'string', ['length' => 30, 'precision' => 0]);
        $table->setPrimaryKey(['id']);
    }

    /**
     * Create oro_user_access_group_role table
     */
    protected function createOroUserAccessGroupRoleTable(Schema $schema)
    {
        $table = $schema->createTable('oro_user_access_group_role');
        $table->addColumn('group_id', 'integer', []);
        $table->addColumn('role_id', 'integer', []);
        $table->addIndex(['group_id'], 'IDX_E7E7E38EFE54D947', []);
        $table->addIndex(['role_id'], 'IDX_E7E7E38ED60322AC', []);
        $table->setPrimaryKey(['group_id', 'role_id']);
    }

    /**
     * Create oro_access_role table
     */
    protected function createOroAccessRoleTable(Schema $schema)
    {
        $table = $schema->createTable('oro_access_role');
        $table->addColumn('id', 'integer', ['precision' => 0, 'autoincrement' => true]);
        $table->addColumn('role', 'string', ['length' => 30, 'precision' => 0]);
        $table->addColumn('label', 'string', ['length' => 30, 'precision' => 0]);
        $table->addColumn(
            'extend_description',
            'text',
            [
                'oro_options' => [
                    'extend'    => ['is_extend' => true, 'owner' => ExtendScope::OWNER_CUSTOM],
                    'datagrid'  => ['is_visible' => DatagridScope::IS_VISIBLE_FALSE],
                    'merge'     => ['display' => true],
                    'dataaudit' => ['auditable' => true],
                    'form'      => ['type' => OroResizeableRichTextType::class],
                    'view'      => ['type' => 'html'],
                ]
            ]
        );
        $table->addUniqueIndex(['role'], 'UNIQ_673F65E757698A6A');
        $table->setPrimaryKey(['id']);
    }

    private function createOroUserLoginAttemptsTable(Schema $schema): void
    {
        $table = $schema->createTable('oro_user_login');
        $table->addColumn('id', 'guid', ['notnull' => false]);
        $table->addColumn('attempt_at', 'datetime', ['comment' => '(DC2Type:datetime)']);
        $table->addColumn('success', 'boolean', ['notnull' => true]);
        $table->addColumn('source', 'integer', ['notnull' => true]);
        $table->addColumn('username', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('user_id', 'integer', ['notnull' => false]);
        $table->addColumn('ip', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('user_agent', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('context', 'json', ['notnull' => true, 'comment' => '(DC2Type:json)']);
        $table->addIndex(['user_id'], 'idx_aa4c6465a76ed395', []);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['attempt_at'], 'oro_user_log_att_at_idx');
    }

    /**
     * Add oro_user_email foreign keys.
     */
    protected function addOroUserEmailForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('oro_user_email');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_user'),
            ['user_id'],
            ['id'],
            []
        );
    }

    /**
     * Add oro_user_api foreign keys.
     */
    protected function addOroUserApiForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('oro_user_api');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_organization'),
            ['organization_id'],
            ['id'],
            ['onDelete' => 'SET NULL', 'onUpdate' => null]
        );
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_user'),
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE', 'onUpdate' => null]
        );
    }

    /**
     * Add oro_user foreign keys.
     */
    protected function addOroUserForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('oro_user');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_business_unit'),
            ['business_unit_owner_id'],
            ['id'],
            ['onDelete' => 'SET NULL']
        );
    }

    /**
     * Add oro_user_access_role foreign keys.
     */
    protected function addOroUserAccessRoleForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('oro_user_access_role');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_user'),
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_access_role'),
            ['role_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
    }

    /**
     * Add oro_user_access_group foreign keys.
     */
    protected function addOroUserAccessGroupForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('oro_user_access_group');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_user'),
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_access_group'),
            ['group_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
    }

    /**
     * Add oro_user_business_unit foreign keys.
     */
    protected function addOroUserBusinessUnitForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('oro_user_business_unit');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_user'),
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_business_unit'),
            ['business_unit_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
    }

    /**
     * Add oro_access_group foreign keys.
     */
    protected function addOroAccessGroupForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('oro_access_group');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_business_unit'),
            ['business_unit_owner_id'],
            ['id'],
            ['onDelete' => 'SET NULL']
        );
    }

    /**
     * Add oro_user_access_group_role foreign keys.
     */
    protected function addOroUserAccessGroupRoleForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('oro_user_access_group_role');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_access_group'),
            ['group_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_access_role'),
            ['role_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
    }

    private function addOroUserLoginAttemptsForeignKeys(Schema $schema): void
    {
        $table = $schema->getTable('oro_user_login');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_user'),
            ['user_id'],
            ['id'],
            ['onUpdate' => null, 'onDelete' => 'CASCADE']
        );
    }

    protected function addOroAccessGroupIndexes(Schema $schema)
    {
        $table = $schema->getTable('oro_access_group');
        $table->addUniqueIndex(['name', 'organization_id'], 'uq_name_org_idx');
        $table->addIndex(['business_unit_owner_id'], 'IDX_FEF9EDB759294170', []);
    }

    protected function addRelationsToScope(Schema $schema)
    {
        if ($schema->hasTable('oro_scope')) {
            $this->extendExtension->addManyToOneRelation(
                $schema,
                'oro_scope',
                'user',
                'oro_user',
                'id',
                [
                    'extend' => [
                        'owner' => ExtendScope::OWNER_CUSTOM,
                        'cascade' => ['all'],
                        'on_delete' => 'CASCADE',
                        'nullable' => true
                    ]
                ],
                RelationType::MANY_TO_ONE
            );
        }
    }

    private function updateOroEmailUserTable(Schema $schema)
    {
        $table = $schema->getTable('oro_email_user');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_email_origin'),
            ['origin_id'],
            ['id'],
            ['onDelete' => 'SET NULL', 'onUpdate' => null]
        );
        $table->addIndex(['origin_id'], 'IDX_91F5CFF656A273CC', []);
    }
}
