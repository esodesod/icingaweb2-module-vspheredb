<?php

namespace Icinga\Module\Vspheredb\DbObject;

use Icinga\Application\Logger;
use Icinga\Exception\ProgrammingError;
use Icinga\Module\Director\Data\Db\DbObject as DirectorDbObject;
use Icinga\Module\Vspheredb\Api;
use Icinga\Module\Vspheredb\Db;
use Icinga\Module\Vspheredb\PropertySet\PropertySet;
use Icinga\Module\Vspheredb\SelectSet\SelectSet;
use Icinga\Module\Vspheredb\VmwareDataType\ManagedObjectReference;

abstract class BaseDbObject extends DirectorDbObject
{
    /** @var Db $connection Exists in parent, but IDEs need a berrer hint */
    protected $connection;

    protected $keyName = 'id';

    /** @var ManagedObject */
    private $object;

    protected $propertyMap = [];

    protected $objectReferences = [];

    protected $booleanProperties = [];

    public function isObjectReference($property)
    {
        return $property === 'parent' || in_array($property, $this->objectReferences);
    }

    public function isBooleanProperty($property)
    {
        return in_array($property, $this->booleanProperties);
    }

    protected function makeBooleanValue($value)
    {
        if ($value === true) {
            return 'y';
        } elseif ($value === false) {
            return 'n';
        } elseif ($value === null) {
            return null;
        } else {
            throw new ProgrammingError(
                'Boolean expected, got %s',
                var_export($value, 1)
            );
        }
    }

    public function setMapped($properties, VCenter $vCenter)
    {
        foreach ($this->propertyMap as $key => $property) {
            if (property_exists($properties, $key)) {
                $value = $properties->$key;
                if ($this->isObjectReference($property)) {
                    $value = $this->createUuidForMoref($value, $vCenter);
                } elseif ($this->isBooleanProperty($property)) {
                    $value = $this->makeBooleanValue($value);
                }

                $this->set($property, $value);
            }
        }

        return $this;
    }

    protected function createUuidForMoref($value, VCenter $vCenter)
    {
        if (empty($value)) {
            return null;
        } elseif ($value instanceof ManagedObjectReference) {
            return $vCenter->makeBinaryGlobalUuid($value->_);
        } else {
            return $vCenter->makeBinaryGlobalUuid($value);
        }
    }

    public function object()
    {
        if ($this->object === null) {
            $this->object = ManagedObject::load($this->get('uuid'), $this->connection);
        }

        return $this->object;
    }

    /**
     * @param Api $api
     * @return array
     */
    public static function fetchAllFromApi(Api $api)
    {
        return $api->propertyCollector()->collectObjectProperties(
            new PropertySet(static::getType(), static::getDefaultPropertySet()),
            static::getSelectSet()
        );
    }

    /**
     * @return SelectSet
     */
    public static function getSelectSet()
    {
        $class = '\\Icinga\\Module\\Vspheredb\\SelectSet\\' . static::getType() . 'SelectSet';
        return new $class;
    }

    public static function getType()
    {
        $parts = explode('\\', get_class(static::dummyObject()));
        return end($parts);
    }

    protected static function getDefaultPropertySet()
    {
        return array_keys(static::dummyObject()->propertyMap);
    }

    protected static function dummyObject()
    {
        return static::create();
    }

    /**
     * @param VCenter $vCenter
     * @param BaseDbObject[] $dbObjects
     * @param BaseDbObject[] $newObjects
     */
    protected static function storeSync(VCenter $vCenter, & $dbObjects, & $newObjects)
    {
        $type = static::getType();
        $vCenterUuid = $vCenter->getUuid();
        $db = $vCenter->getConnection();
        $dba = $vCenter->getDb();
        Logger::debug("Ready to store $type");
        $dba->beginTransaction();
        $modified = 0;
        $created = 0;
        $dummy = static::dummyObject();
        $newUuids = [];
        foreach ($newObjects as $object) {
            $uuid = $vCenter->makeBinaryGlobalUuid($object->id);

            $newUuids[$uuid] = $uuid;
            if (array_key_exists($uuid, $dbObjects)) {
                $dbObject = $dbObjects[$uuid];
            } else {
                $dbObjects[$uuid] = $dbObject = static::create([
                    'uuid' => $uuid,
                    'vcenter_uuid' => $vCenterUuid
                ], $db);
            }
            $dbObject->setMapped($object, $vCenter);
            if ($dbObject->hasBeenLoadedFromDb()) {
                if ($dbObject->hasBeenModified()) {
                    $dbObject->store();
                    $modified++;
                }
            } else {
                $dbObject->store();
                $created++;
            }
        }

        $del = [];
        foreach ($dbObjects as $existing) {
            $uuid = $existing->get('uuid');
            if (! array_key_exists($uuid, $newUuids)) {
                $del[] = $uuid;
            }
        }
        if (! empty($del)) {
            $dba->delete(
                $dummy->getTableName(),
                $dba->quoteInto('uuid IN (?)', $del)
            );

        }
        $dba->commit();
        Logger::debug(
            "$type: %d new, %d modified, %d deleted (got %d from API)",
            $created,
            $modified,
            count($del),
            count($newObjects)
        );
    }

    public static function onStoreSync(Db $db)
    {
    }

    /**
     * @param VCenter $vCenter
     * @return static[]
     */
    public static function loadAllForVCenter(VCenter $vCenter)
    {
        $dummy = new static();

        return static::loadAll(
            $vCenter->getConnection(),
            $vCenter->getDb()
                ->select()
                ->from($dummy->getTableName())
                ->where('vcenter_uuid = ?', $vCenter->get('uuid')),
            $dummy->keyName
        );
    }

    public static function syncFromApi(VCenter $vCenter)
    {
        $type = static::getType();
        $db = $vCenter->getConnection();
        Logger::debug("Loading existing $type from DB");
        $existing = static::loadAllForVCenter($vCenter);
        Logger::debug("Got %d existing $type", count($existing));
        $objects = static::fetchAllFromApi($vCenter->getApi());
        Logger::debug("Got %d $type from VCenter", count($objects));
        static::storeSync($vCenter, $existing, $objects);
        static::onStoreSync($db);
    }
}
