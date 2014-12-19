<?php

use Db\ReplicatedConnection;

class IsWriteQueryTest extends PHPUnit_Framework_TestCase {

  function test_db_is_write_query() {
    $queries = array(
      'insert into foo set bar=?',
      'delete from foo where id=1',
      'delete from foo where id in (select id from bar)',
      "delete\nfrom foo",
      "insert\ninto foo values(null)",
      "\ninsert into foo values(null)",
      "UPDATE jobs SET meow=?",
    );
    foreach ($queries as $q) {
      $this->assertTrue(ReplicatedConnection::isWriteQuery($q), "'$q' should be a write query");
    }
  }

  function test_db_is_not_write_query() {
    $queries = array(
      "SELECT * FROM users",
      "SELECT * FROM users WHERE id=?",
      "select * from users",
      "SELECT * FROM users_update WHERE id=?",
      "SELECT * FROM users_insert WHERE delete_id=? OR insert_update=?",
      "SELECT * FROM users_delete WHERE update_id=?",
      "select * from users where id in (select id from bar)",
      "select * from users join x",
      "select\n* from users",
      "\nselect * from users",
      // these queries should explicitly be db_master() if the master is necessary
      "show global status",
      "show slave status",
      "SELECT meow FROM jobs",
    );
    foreach ($queries as $q) {
      $this->assertFalse(ReplicatedConnection::isWriteQuery($q), "'$q' should not be a write query");
    }
  }

}
