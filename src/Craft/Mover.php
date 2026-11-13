<?php

namespace facilo\rumbo\Craft;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\Queue;
use facilo\rumbo\Core\EtherMapping;
use facilo\rumbo\Craft\fields\Location;
use facilo\rumbo\Craft\jobs\MoveJob;
use facilo\rumbo\migrations\Install;
use facilo\rumbo\Plugin;

/**
 * Move from Maps (F3). Reads the old plugin's data straight from the database, the
 * `{{%maps}}` table (owner, site, field, lat, lng) and the field's entry in the element's
 * content JSON (zoom, address, parts), and never loads the old plugin's code.
 *
 * The old content JSON is left as it is: a Rumbo field reads that shape on its own, so a
 * rolled-back field loses nothing.
 */
class Mover extends Component
{
    public const OLD_TYPE = 'ether\\simplemap\\fields\\MapField';
    public const OLD_TABLE = '{{%maps}}';
    public const BATCH = 500;

    /** Set while this site switches a field itself, so the project config handler leaves it alone. */
    public bool $switching = false;

    public function oldTableExists(): bool
    {
        return Craft::$app->getDb()->tableExists(self::OLD_TABLE);
    }

    /** Maps fields still to move, plus fields already moved that have a report. */
    public function fields(): array
    {
        $rows = (new Query())->select(['id', 'uid', 'name', 'handle', 'type', 'settings'])
            ->from(Table::FIELDS)
            ->where(['type' => [self::OLD_TYPE, Location::class]])
            ->orderBy(['name' => SORT_ASC])
            ->all();
        $out = [];
        foreach ($rows as $row) {
            $settings = Json::decodeIfJson($row['settings'] ?? '') ?: [];
            $move = $this->lastMove((int)$row['id']);
            if ($row['type'] !== self::OLD_TYPE && !$move) {
                continue;
            }
            $old = $row['type'] === self::OLD_TYPE ? $this->counts((int)$row['id']) : ['rows' => (int)($move['oldCount'] ?? 0), 'skipped' => 0];
            $out[] = [
                'id' => (int)$row['id'],
                'uid' => $row['uid'],
                'name' => $row['name'],
                'handle' => $row['handle'],
                'moved' => $row['type'] !== self::OLD_TYPE,
                'locations' => $old['rows'] - $old['skipped'],
                'skipped' => $old['skipped'],
                'settings' => $row['type'] === self::OLD_TYPE ? EtherMapping::convertSettings($settings) : null,
                'usedIn' => $this->usedIn((int)$row['id']),
                'move' => $move,
            ];
        }
        return $out;
    }

    /** Names of the entry types, users, assets and so on whose layouts hold the field. */
    public function usedIn(int $fieldId): array
    {
        $names = [];
        try {
            $field = Craft::$app->getFields()->getFieldById($fieldId);
            if ($field === null) {
                return [];
            }
            foreach (Craft::$app->getFields()->findFieldUsages($field) as $layout) {
                $provider = method_exists($layout, 'getProvider') ? $layout->getProvider() : null;
                $name = $provider->name ?? null;
                if (!$name && $layout->type) {
                    $name = $layout->type::pluralDisplayName();
                }
                if ($name) {
                    $names[$name] = $name;
                }
            }
        } catch (\Throwable $e) {
            Craft::warning('Could not list the layouts for field ' . $fieldId . ': ' . $e->getMessage(), 'rumbo-maps');
        }
        return array_values($names);
    }

    /**
     * Project config applied on another environment (production) can switch a Maps field to
     * Rumbo before any move ran there. The type changes, but the locations still sit in the
     * Maps table, so this queues the same move, which copies them and logs the same report.
     */
    public function onFieldConfigUpdate(\craft\events\ConfigEvent $event): ?int
    {
        if ($this->switching) {
            return null;
        }
        $oldType = $event->oldValue['type'] ?? null;
        $newType = $event->newValue['type'] ?? null;
        if ($oldType !== self::OLD_TYPE || $newType !== Location::class || !$this->oldTableExists()) {
            return null;
        }
        $uid = $event->tokenMatches[0] ?? null;
        $fieldId = $uid ? (new Query())->select('id')->from(Table::FIELDS)->where(['uid' => $uid])->scalar() : null;
        if (!$fieldId) {
            return null;
        }
        $converted = EtherMapping::convertSettings(is_array($event->oldValue['settings'] ?? null) ? $event->oldValue['settings'] : []);
        $moveId = $this->start((int)$fieldId, ['fromProjectConfig' => true, 'notCarried' => $converted['notCarried']]);
        Craft::info("Field $uid changed from Maps to Rumbo through project config. Queued move $moveId to copy its locations.", 'rumbo-maps');
        Queue::push(new MoveJob(['moveId' => $moveId]));
        return $moveId;
    }

    /** @return array{rows:int,skipped:int} */
    public function counts(int $fieldId, bool $liveOnly = true): array
    {
        if (!$this->oldTableExists()) {
            return ['rows' => 0, 'skipped' => 0];
        }
        // Shown to people, so only live entries count; the move itself also carries drafts and revisions.
        $base = fn() => $liveOnly ? self::live((new Query())->from(['m' => self::OLD_TABLE])->where(['m.fieldId' => $fieldId])) : (new Query())->from(['m' => self::OLD_TABLE])->where(['m.fieldId' => $fieldId]);
        $rows = (int)$base()->count();
        $skipped = (int)$base()->andWhere(['or', ['m.lat' => null], ['m.lng' => null]])->count();
        return ['rows' => $rows, 'skipped' => $skipped];
    }

    public function lastMove(int $fieldId): ?array
    {
        $row = (new Query())->from(Install::MOVES)->where(['fieldId' => $fieldId])->orderBy(['id' => SORT_DESC])->one();
        if ($row) {
            $row['report'] = self::decodeReport($row['report'] ?? null);
        }
        return $row ?: null;
    }

    /** Starts the move of one field (or all) as queue jobs. Returns the move ids. */
    public function queue(?string $handle = null): array
    {
        $ids = [];
        foreach ($this->fields() as $field) {
            if ($field['moved'] || ($handle !== null && $field['handle'] !== $handle)) {
                continue;
            }
            $id = $this->start($field['id']);
            Queue::push(new MoveJob(['moveId' => $id]));
            $ids[] = $id;
        }
        return $ids;
    }

    /** A dry run: what the move would do, without writing. */
    public function dryRun(?string $handle = null): array
    {
        $report = [];
        foreach ($this->fields() as $field) {
            if ($field['moved'] || ($handle !== null && $field['handle'] !== $handle)) {
                continue;
            }
            $skips = ['empty' => 0, 'missing-lat-lng' => 0];
            $ok = 0;
            $this->each($field['id'], 0, function(array $old) use (&$skips, &$ok) {
                $converted = EtherMapping::convert($old);
                $converted['ok'] ? $ok++ : $skips[$converted['reason']]++;
            }, null, true);
            $report[$field['handle']] = [
                'name' => $field['name'],
                'locations' => $ok,
                'skipped' => $skips,
                'notCarried' => $field['settings']['notCarried'] ?? [],
            ];
        }
        return $report;
    }

    public function start(int $fieldId, array $report = []): int
    {
        $uid = (new Query())->select('uid')->from(Table::FIELDS)->where(['id' => $fieldId])->scalar();
        Db::insert(Install::MOVES, [
            'fieldId' => $fieldId,
            'fieldUid' => $uid,
            'status' => 'pending',
            'oldCount' => 0,
            'newCount' => 0,
            'lastOldId' => 0,
            'report' => $report,
        ]);
        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * Runs (or resumes) one move. Copies in batches of 500, saving progress after each,
     * then checks the counts and switches the field type, or rolls the field back.
     *
     * @param callable|null $progress fn(float $done, string $label)
     */
    public function run(int $moveId, ?callable $progress = null): array
    {
        $move = (new Query())->from(Install::MOVES)->where(['id' => $moveId])->one();
        if (!$move) {
            throw new \RuntimeException("Move $moveId not found.");
        }
        $fieldId = (int)$move['fieldId'];
        $report = self::decodeReport($move['report'] ?? null);
        $report += ['copied' => 0, 'skipped' => ['empty' => 0, 'missing-lat-lng' => 0], 'notCarried' => [], 'messages' => []];
        if (!$this->oldTableExists()) {
            return $this->finish($moveId, 'failed', $report, 'The Maps table is gone, so there is nothing to copy. Run the move before uninstalling Maps.');
        }
        $counts = $this->counts($fieldId, false);
        $total = max(1, $counts['rows']);
        $lastId = (int)$move['lastOldId'];
        Db::update(Install::MOVES, ['status' => 'running', 'oldCount' => $counts['rows'] - $counts['skipped']], ['id' => $moveId]);

        $done = (int)(new Query())->from(self::OLD_TABLE)->where(['fieldId' => $fieldId])->andWhere(['<=', 'id', $lastId])->count();
        while (true) {
            $batch = [];
            $lastSeen = $lastId;
            $this->each($fieldId, $lastId, function(array $old) use (&$batch, &$report, &$lastSeen, $fieldId) {
                $lastSeen = (int)$old['id'];
                $converted = EtherMapping::convert($old);
                if (!$converted['ok']) {
                    $report['skipped'][$converted['reason']]++;
                    return;
                }
                $value = new LocationValue($converted['row']);
                $batch[] = ['elementId' => (int)$old['ownerId'], 'siteId' => (int)$old['ownerSiteId'], 'fieldId' => $fieldId]
                    + Plugin::getInstance()->locations->row($value);
            }, self::BATCH);
            if ($lastSeen === $lastId) {
                break;
            }
            $transaction = Craft::$app->getDb()->beginTransaction();
            try {
                foreach ($batch as $row) {
                    Db::upsert(Install::LOCATIONS, $row);
                }
                $report['copied'] += count($batch);
                Db::update(Install::MOVES, ['lastOldId' => $lastSeen, 'report' => $report], ['id' => $moveId]);
                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }
            $done += self::BATCH;
            $lastId = $lastSeen;
            if ($progress) {
                $progress(min(1, $done / $total), "Copied {$report['copied']} locations");
            }
        }

        $newCount = (int)(new Query())->from(Install::LOCATIONS)->where(['fieldId' => $fieldId])->count();
        $expected = $counts['rows'] - $counts['skipped'];
        $copiedOk = $newCount === $expected && $report['skipped']['missing-lat-lng'] + $report['skipped']['empty'] === $counts['skipped'];
        Db::update(Install::MOVES, ['newCount' => $newCount], ['id' => $moveId]);
        if (!empty($report['fromProjectConfig'])) {
            // The type already changed through project config, so there is nothing to roll back to.
            // Copied rows stay: they are correct, and a field with some rows beats one with none.
            return $copiedOk
                ? $this->finish($moveId, 'done', $report, null)
                : $this->finish($moveId, 'failed', $report, "Counts didn't match (Maps $expected, Rumbo $newCount). The field already uses Rumbo through project config, so it was not switched back. The Maps table is untouched.");
        }
        if (!$copiedOk) {
            $this->rollBack($fieldId);
            return $this->finish($moveId, 'rolledBack', $report, "Counts didn't match (Maps $expected, Rumbo $newCount), so this field was rolled back and still uses Maps.");
        }
        $report['notCarried'] = $this->switchType($fieldId);
        return $this->finish($moveId, 'done', $report, null);
    }

    /** Calls $fn for each old location of a field after $afterId, the maps row merged with its content JSON. */
    private static function live(Query $query): Query
    {
        return $query->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[m.ownerId]]')
            ->andWhere(['e.revisionId' => null, 'e.draftId' => null, 'e.dateDeleted' => null]);
    }

    public function each(int $fieldId, int $afterId, callable $fn, ?int $limit = null, bool $liveOnly = false): void
    {
        $query = (new Query())
            ->select(['m.id', 'm.ownerId', 'm.ownerSiteId', 'm.fieldId', 'm.lat', 'm.lng', 'es.content'])
            ->from(['m' => self::OLD_TABLE])
            ->leftJoin(['es' => Table::ELEMENTS_SITES], '[[es.elementId]] = [[m.ownerId]] AND [[es.siteId]] = [[m.ownerSiteId]]')
            ->where(['m.fieldId' => $fieldId])
            ->andWhere(['>', 'm.id', $afterId])
            ->orderBy(['m.id' => SORT_ASC]);
        if ($limit) {
            $query->limit($limit);
        }
        if ($liveOnly) {
            self::live($query);
        }
        foreach ($query->each(200) as $row) {
            $content = Json::decodeIfJson($row['content'] ?? '') ?: [];
            unset($row['content']);
            $fn(self::contentFor($content, $fieldId) + $row);
        }
    }

    /** The old value in the content JSON is keyed by its layout element; it carries its fieldId. */
    public static function contentFor(array $content, int $fieldId): array
    {
        foreach ($content as $value) {
            if (is_array($value) && (int)($value['fieldId'] ?? 0) === $fieldId && (array_key_exists('lat', $value) || array_key_exists('parts', $value))) {
                return array_intersect_key($value, array_flip(['zoom', 'address', 'parts']));
            }
        }
        return [];
    }

    /** Switches the field type through Craft's Fields service, keeping handle, name, instructions and layouts. */
    private function switchType(int $fieldId): array
    {
        $fields = Craft::$app->getFields();
        $old = $fields->getFieldById($fieldId);
        if ($old === null) {
            return [];
        }
        $converted = EtherMapping::convertSettings($old->getSettings());
        $new = $fields->createField([
            'type' => Location::class,
            'id' => $old->id,
            'uid' => $old->uid,
            'name' => $old->name,
            'handle' => $old->handle,
            'context' => $old->context,
            'instructions' => $old->instructions,
            'searchable' => $old->searchable,
            'translationMethod' => $old->translationMethod,
            'translationKeyFormat' => $old->translationKeyFormat,
            'settings' => $converted['settings'],
        ]);
        $this->switching = true;
        try {
            $saved = $fields->saveField($new, false);
        } finally {
            $this->switching = false;
        }
        if (!$saved) {
            throw new \RuntimeException('Could not switch field “' . $old->handle . '”: ' . implode(' ', $new->getFirstErrors()));
        }
        return $converted['notCarried'];
    }

    /** Reports are kept in a JSON column, and older rows hold them encoded twice. */
    private static function decodeReport(mixed $raw): array
    {
        for ($i = 0; $i < 2 && is_string($raw); $i++) {
            $raw = json_decode($raw, true);
        }
        return is_array($raw) ? $raw : [];
    }

    private function rollBack(int $fieldId): void
    {
        Db::delete(Install::LOCATIONS, ['fieldId' => $fieldId]);
    }

    private function finish(int $moveId, string $status, array $report, ?string $message): array
    {
        if ($message) {
            $report['messages'][] = $message;
        }
        Db::update(Install::MOVES, ['status' => $status, 'report' => $report], ['id' => $moveId]);
        $line = 'Move from Maps ' . $status . ': ' . Json::encode($report);
        $status === 'done' ? Craft::info($line, 'rumbo-maps') : Craft::warning($line, 'rumbo-maps');
        return ['status' => $status] + $report;
    }
}
