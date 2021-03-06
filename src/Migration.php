<?php

namespace Db;

use \Monolog\Logger;

/**
 * Represents a database "migration", which can be composed together with other
 * migrations across multiple components to initialise a database and update it
 * with updates.
 */
class Migration {

  /**
   * Cache migration table checks so we only do it once per query
   */
  static $checked_migrations_table = false;

  /**
   * @return true if this migration is applied
   */
  function isApplied(Connection $db) {
    // don't error if we don't have any migration parent table
    if (!Migration::$checked_migrations_table) {
      $base = new BaseMigration();
      Migration::$checked_migrations_table = $base->isApplied($db);
    }

    if (!Migration::$checked_migrations_table) {
      return false;
    }

    $q = $db->prepare("SELECT * FROM migrations WHERE name=?");
    $q->execute(array($this->getName()));

    if ($q->fetch()) {
      return true;
    } else {
      return false;
    }
  }

  function getName() {
    return get_class($this);
  }

  /**
   * Get all parent {@link Migration}s that this migration depends on, as a list
   */
  function getParents() {
    return array(new BaseMigration());
  }

  /**
   * Get all our parent {@link Migration}s along with all of its parents migrations
   * into one unique array.
   */
  function getAllParents() {
    $result = array($this->getName() => $this);
    foreach ($this->getParents() as $parent) {
      $result += $parent->getAllParents();
    }
    return $result;
  }

  /**
   * Install the current migration and any parent migrations that this migration depends on.
   */
  function install(Connection $db, Logger $log) {
    // bail if we've already applied
    if ($this->isApplied($db)) {
      $log->info("Skipping " . $this->getName() . ": already applied");
      return;
    }

    // simply make sure all parent migrations are applied
    foreach ($this->getParents() as $migration) {
      $migration->install($db, $log);
    }

    // check we have a valid name
    if (strlen($this->getName()) == 0 || strlen($this->getName()) > 255) {
      throw new DbException("Invalid migration name '" . $this->getName() . "'");
    }

    // and then install our own
    try {
      $log->info("Applying " . $this->getName());
      if ($this->apply($db)) {
        $log->info("Applied migration " . $this->getName());
      } else {
        $log->error("Could not apply migration '" . $this->getName() . "': " . $db->lastError());
        throw new DbException("Could not apply migration " . $this->getName());
      }
    } catch (DbException $e) {
      $log->error("Could not apply migration '" . $this->getName() . "': " . $e->getMessage());
      throw new DbException("Could not apply migration " . $this->getName(), $e);
    }

    // save migration status
    $q = $db->prepare("INSERT INTO migrations SET name=?");
    $q->execute(array($this->getName()));
  }

  /**
   * @return true if this migration, or any of its parents, have pending migrations
   */
  function hasPending(Connection $db) {
    if (!$this->isApplied($db)) {
      return true;
    }

    foreach ($this->getParents() as $parent) {
      if ($parent->hasPending($db)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Apply only the current migration.
   * @return true on success or false on failure
   */
  function apply(Connection $db) {
    // empty by default
    return true;
  }

  /**
   * Used e.g. in BaseMigration
   * @return true if the given table exists
   */
  function tableExists(Connection $db, $table) {
    $q = $db->prepare("SHOW TABLES LIKE ?");
    $q->execute(array($table));

    if ($q->fetch()) {
      return true;
    } else {
      return false;
    }
  }

}
