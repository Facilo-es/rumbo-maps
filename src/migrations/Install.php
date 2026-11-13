<?php

namespace facilo\rumbo\migrations;

use craft\db\Migration;
use craft\db\Table;

/** Creates Rumbo's four tables (spec section 6). The same tables are used on Craft 6. */
class Install extends Migration
{
    public const LOCATIONS = '{{%rumbo_locations}}';
    public const GEOCACHE = '{{%rumbo_geocache}}';
    public const INSIGHTS = '{{%rumbo_insights}}';
    public const MOVES = '{{%rumbo_moves}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::LOCATIONS)) {
            $this->createTable(self::LOCATIONS, [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'fieldId' => $this->integer()->notNull(),
                'lat' => $this->decimal(11, 9),
                'lng' => $this->decimal(12, 9),
                'zoom' => $this->smallInteger(),
                'address' => $this->string(255),
                'parts' => $this->json(),
                'countryCode' => $this->char(2),
                'serviceRadius' => $this->decimal(8, 3),
                'source' => $this->string(16),
                'geocodedAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::LOCATIONS, ['elementId', 'siteId', 'fieldId'], true);
            $this->createIndex(null, self::LOCATIONS, ['fieldId', 'siteId', 'lat', 'lng']);
            $this->createIndex(null, self::LOCATIONS, ['source', 'geocodedAt']);
            $this->addForeignKey(null, self::LOCATIONS, 'elementId', Table::ELEMENTS, 'id', 'CASCADE');
            $this->addForeignKey(null, self::LOCATIONS, 'siteId', Table::SITES, 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, self::LOCATIONS, 'fieldId', Table::FIELDS, 'id', 'CASCADE');
        }
        if (!$this->db->tableExists(self::GEOCACHE)) {
            $this->createTable(self::GEOCACHE, [
                'hash' => $this->char(64)->notNull(),
                'provider' => $this->string(16)->notNull(),
                'result' => $this->json(),
                'dateCreated' => $this->dateTime()->notNull(),
                'expires' => $this->dateTime()->notNull(),
                'PRIMARY KEY([[hash]])',
            ]);
            $this->createIndex(null, self::GEOCACHE, ['expires']);
        }
        if (!$this->db->tableExists(self::INSIGHTS)) {
            $this->createTable(self::INSIGHTS, [
                'id' => $this->primaryKey(),
                'date' => $this->date()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'placeLabel' => $this->string(120)->notNull(),
                'latRounded' => $this->decimal(4, 1),
                'lngRounded' => $this->decimal(5, 1),
                'searches' => $this->integer()->notNull()->defaultValue(0),
                'noResults' => $this->integer()->notNull()->defaultValue(0),
                'usedMyLocation' => $this->integer()->notNull()->defaultValue(0),
            ]);
            $this->createIndex(null, self::INSIGHTS, ['date', 'siteId', 'placeLabel'], true);
            $this->addForeignKey(null, self::INSIGHTS, 'siteId', Table::SITES, 'id', 'CASCADE', 'CASCADE');
        }
        if (!$this->db->tableExists(self::MOVES)) {
            $this->createTable(self::MOVES, [
                'id' => $this->primaryKey(),
                'fieldId' => $this->integer()->notNull(),
                'fieldUid' => $this->uid(),
                'status' => $this->string(16)->notNull(),
                'oldCount' => $this->integer()->notNull()->defaultValue(0),
                'newCount' => $this->integer()->notNull()->defaultValue(0),
                'lastOldId' => $this->integer()->notNull()->defaultValue(0),
                'report' => $this->json(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::MOVES, ['fieldId']);
        }
        return true;
    }

    public function safeDown(): bool
    {
        foreach ([self::MOVES, self::INSIGHTS, self::GEOCACHE, self::LOCATIONS] as $table) {
            $this->dropTableIfExists($table);
        }
        return true;
    }
}
