<?php

namespace JazzManDB;

use Exception;
use wpdb;

if (!defined('WPINC')) {
	die;
}

/**
 * The wrapper class to help WordPress CRUD for a single table.
 *
 * How To Use
 * $mydb = new Db( 'mytable_without_wpdb_prefix' );
 * $all_data  = $mydb->get_all( $orderby = 'date', $order = 'ASC' );
 * $row_data  = $mydb->get_row( $column = 'id', $value = 102, $format = '%d', $output_type = OBJECT, $offset = 10 );
 * $columns   = $mydb->get_columns();
 * $get_by    = $mydb->get_by(
 *                          $columns     = array( 'id', 'slug' ),
 *                          $field       = 'id',
 *                          $field_value = 102,
 *                          $operator    = '=',
 *                          $format      = '%d',
 *                          $orderby     = 'slug',
 *                          $order       = 'ASC',
 *                          $output_type = OBJECT_K
 *                      );
 * $get_wheres = $mydb->get_wheres(
 *                          $column      = '*',
 *                          $conditions  = array(
 *                                             'category' => $category,
 *                                             'id'     => $id
 *                                        ),
 *                          $operator    = '=',
 *                          $format      = array(
 *                                              'category' => '%s',
 *                                              'id' => '%d'
 *                                        ),
 *                          $orderby     = 'category',
 *                          $order       = 'ASC',
 *                          $output_type = OBJECT_K
 *                      );
 * $insert_id = $mydb->insert( $data = array( 'title' => 'text', 'date' => date("Y-m-d H:i:s") ) );
 */
class DB
{
	/**
	 * @var string
	 */
	public $query = '';
	/**
	 * @var string
	 */
	public $prefix;
	/**
	 * The current table name.
	 *
	 * @var string
	 */
	protected $tablename;
	/**
	 * @var string
	 */
	protected $charset;
	/**
	 * @var wpdb
	 */
	protected $wpdb;

	/**
	 * @var
	 */
	private $column;

	/**
	 * Constructor for the class to inject the table name.
	 *
	 * @since 0.0.1
	 *
	 * @param string $tablename The table name
	 * @param bool   $prefix
	 */
	public function __construct($tablename, $prefix = false)
	{
		global $wpdb;
		if (null === $this->wpdb) {
			$this->wpdb = $wpdb;
			$this->prefix = $prefix ? $wpdb->prefix : '';
			$this->charset = $wpdb->get_charset_collate();
		}
		$prefix_len = mb_strlen($this->prefix);
		if ($prefix_len > 0) {
			$this->tablename = 0 === mb_stripos($tablename, $prefix_len) ? $tablename : $this->prefix.$tablename;
		} else {
			$this->tablename = $tablename;
		}

		$this->setColumn();
	}

	private function setColumn()
	{
		$this->column = $this->wpdb->get_col("DESCRIBE $this->tablename");
	}

	/**
	 * @return string
	 */
	public function getTablename()
	{
		return $this->tablename;
	}

	/**
	 * @param $method
	 * @param $arguments
	 *
	 * @return mixed
	 *
	 * @throws Exception
	 */
	public function __call($method, $arguments)
	{
		if (method_exists($this, $method)) {
			if ($this->table_exists()) {
				return call_user_func_array([$this, $method], $arguments);
			}
			throw new Exception(sprintf('Table for %s Not Exists in the Database', $this->tablename), 1);
		}
	}

	/**
	 * Check if the specified table exists in database.
	 *
	 * @since  0.0.1
	 *
	 * @return bool
	 */
	public function table_exists()
	{
		$table = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES like %s', $this->tablename));

		return $table === $this->tablename;
	}

	/**
	 * @param array|null $query
	 */
	public function table_create(array $query = null)
	{
		if (empty($query) || $this->table_exists()) {
			return;
		}

		$query = implode(',', $query);
		$sql = "CREATE TABLE $this->tablename ($query) $this->charset;";
		require_once ABSPATH.'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}

	/**
	 * Get all from the selected table.
	 *
	 * @since  0.0.1
	 *
	 * @param null|string $orderby     The column for ordering base
	 * @param string      $order       Order key eq. ASC or DESC
	 * @param array|null  $limit       Limit
	 * @param mixed       $output_type
	 *
	 * @return array $results    The query results
	 */
	public function get_all($orderby = null, $order = null, array $limit = null, $output_type = OBJECT)
	{
		$sql = "SELECT * FROM $this->tablename";
		if (null !== $orderby) {
			$orderby = $this->check_column($orderby);
			$order = null !== $order ? $this->check_order($order) : null;
			if ($orderby) {
				$sql .= " ORDER BY $orderby";
				if ($order) {
					$sql .= " $order";
				}
			}
		}
		if (null !== $limit) {
			$sql .= ' LIMIT ';
			$sql .= implode(',', $limit);
		}

		return $this->wpdb->get_results($sql, $output_type);
	}

	/** @noinspection MultipleReturnStatementsInspection
	 * @param string|array $columns
	 * @param string       $return
	 *
	 * @return string
	 */
	protected function check_column($columns, $return = 'string')
	{
		$_columns = $this->getColumn();
		if (is_array($columns)) {
			foreach ((array) $columns as $key => $value) {
				if (!in_array($value, $_columns, true)) {
					unset($columns[$key]);
				}
			}
			if (!empty($columns)) {
				if ('string' === $return) {
					return implode(',', $columns);
				}

				return $columns;
			}

			return '*';
		}
		if ('*' === $columns) {
			return $columns;
		}
		if (in_array($columns, $_columns, true)) {
			return $columns;
		}

		return '*';
	}

	/**
	 * @return mixed
	 */
	public function getColumn()
	{
		return $this->column;
	}

	/**
	 * check/sanitize ORDER string.
	 *
	 * @since  0.0.1
	 *
	 * @return string order string ASC|DESC
	 *
	 * @param mixed $order
	 */
	protected function check_order($order = 'ASC')
	{
		if (null === $order) {
			return 'ASC';
		}
		$order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'ASC';

		return $order;
	}

	/**
	 * @param string|array $column
	 * @param array        $conditions
	 * @param string       $operator
	 * @param string       $format
	 * @param string       $output_type
	 * @param int          $row_offset
	 *
	 * @return array|null|object
	 */
	public function get_row($column, array $conditions, $operator = '=', $format = '%s', $output_type = OBJECT, $row_offset = 0)
	{
		$format = $this->check_format($format);
		$operator = $this->check_operator($operator);
		$column = $this->check_column($column);

		$sql = "SELECT $column FROM $this->tablename ";
		$sql .= 'WHERE 1=1 ';

		$i = 0;
		foreach ($conditions as $field => $value) {
			if (!$value) {
				++$i;
				continue;
			}
			if (is_array($operator)) {
				if (isset($operator[$field])) {
					$op = $operator[$field];
				} elseif (isset($operator[$i])) {
					$op = $operator[$i];
				} else {
					$op = '=';
				}
			} else {
				$op = $operator;
			}
			if (is_array($format)) {
				if (isset($format[$field])) {
					$f = $format[$field];
				} elseif (isset($format[$i])) {
					$f = $format[$i];
				} else {
					$f = '%s';
				}
			} else {
				$f = $format;
			}
			$method = 'sql_'.mb_strtolower(str_replace(' ', '_', $op));
			if (method_exists($this, $method)) {
				$sql .= $this->$method($field, $value, $f, true);
			} else {
				$sql .= $this->sql_default($field, $value, $op, $f, true);
			}
			++$i;
		}

		return $this->wpdb->get_row($sql, $output_type, $row_offset);
	}

	/**
	 * check/sanitize format string
	 *
	 * @param  string|array $format   The array of formats or single format string need to be check.
	 * @return string|array    The Array of checked formats or single checked format string.
	 *
	 */

	protected function check_format($format)
	{
		$formats = [];
		if (is_array($format)) {
			foreach ($format as $k => $f) {
				$formats[$k] = $this->check_format($f);
			}

			return $formats;
		}
		$format = (in_array($format, ['%s', '%d', '%f'], true) ? $format : '%s');

		return $format;
	}

	/**
	 * @param string $column
	 * @param $field
	 * @param $value
	 * @param string $operator
	 * @param string $format
	 * @param null   $orderby
	 * @param string $order
	 * @param string $output_type
	 *
	 * @return array
	 */
	public function get_by(
		$column = '',
		$field,
		$value,
		$operator = '=',
		$format = '%s',
		$orderby = null,
		$order = 'ASC',
		$output_type = OBJECT
	) {
		$order = $this->check_order($order);
		$operator = $this->check_operator($operator);

		$format = $this->check_format($format);
		$column = $this->check_column($column);
		$sql = "SELECT $column FROM $this->tablename WHERE";
		$method = 'sql_'.mb_strtolower(str_replace(' ', '_', $operator));
		if (method_exists($this, $method)) {
			$sql .= $this->$method($field, $value, $format, false);
		} else {
			$sql .= $this->sql_default($field, $value, $operator, $format, false);
		}
		if (null !== $orderby) {
			$orderby = $this->check_column($orderby);
			$order = null !== $order ? $this->check_order($order) : null;
			if ($orderby) {
				$sql .= " ORDER BY $orderby";
				if ($order) {
					$sql .= " $order";
				}
			}
		}

		return $this->wpdb->get_results($sql, $output_type);
	}

	/** @noinspection MultipleReturnStatementsInspection
	 * @param $operator
	 *
	 * @return array|string
	 */
	protected function check_operator($operator)
	{
		$operators = [];
		if (is_array($operator)) {
			foreach ($operator as $k => $op) {
				$operators[$k] = $this->check_operator($op);
			}

			return $operators;
		}
		$operator = (in_array($operator, $this->get_operands(), true) ? mb_strtoupper($operator) : '=');

		return $operator;
	}

	/**
	 * @return array
	 */
	protected function get_operands()
	{
		return apply_filters(__METHOD__, [
			'=',
			'!=',
			'>',
			'<',
			'>=',
			'<=',
			'<=>',
			'like',
			'not like',
			'in',
			'not in',
			'between',
			'not between',
			'custom',
		]);
	}

	/**
	 * @param $column
	 * @param $value
	 * @param $op
	 * @param string $format
	 * @param bool   $and
	 *
	 * @return string
	 */
	protected function sql_default($column, $value, $op, $format = '%s', $and = true)
	{
		$sql = $this->_sql_and($and);
		$sql .= $this->wpdb->prepare(" `$column` $op $format", $value);

		return $sql;
	}

	/**
	 * @param bool $and
	 *
	 * @return string
	 */
	protected function _sql_and($and = true)
	{
		$_and = '';

		if (true === $and) {
			$_and = ' AND';
		} elseif ('OR' === mb_strtoupper($and)) {
			$_and = ' OR';
		}

		return $_and;
	}

	/**
	 * @param $column
	 * @param array  $conditions
	 * @param string $operator
	 * @param string $format
	 *
	 * @return null|string
	 */
	public function get_var($column, array $conditions, $operator = '=', $format = '%s')
	{
		$operator = $this->check_operator($operator);
		$column = $this->check_column($column);
		$sql = "SELECT $column FROM $this->tablename ";
		$sql .= 'WHERE 1=1 ';
		$i = 0;
		foreach ($conditions as $field => $value) {
			if (!$value) {
				++$i;
				continue;
			}
			if (is_array($operator)) {
				if (isset($operator[$field])) {
					$op = $operator[$field];
				} elseif (isset($operator[$i])) {
					$op = $operator[$i];
				} else {
					$op = '=';
				}
			} else {
				$op = $operator;
			}
			if (is_array($format)) {
				if (isset($format[$field])) {
					$f = $format[$field];
				} elseif (isset($format[$i])) {
					$f = $format[$i];
				} else {
					$f = '%s';
				}
			} else {
				$f = $format;
			}
			$method = 'sql_'.mb_strtolower(str_replace(' ', '_', $op));
			if (method_exists($this, $method)) {
				$sql .= $this->$method($field, $value, $f, true);
			} else {
				$sql .= $this->sql_default($field, $value, $op, $f, true);
			}
			++$i;
		}

		return $this->wpdb->get_var($sql);
	}

	/**
	 * @param string $column
	 * @param null   $join
	 * @param array  $conditions
	 * @param string $operator
	 * @param string $format
	 * @param null   $orderby
	 * @param string $order
	 * @param null   $limit
	 * @param string $output_type
	 *
	 * @return array
	 */
	public function get_wheres(
		$column = '',
		$join = null,
		array $conditions,
		$operator = '=',
		$format = '%s',
		$orderby = null,
		$order = 'ASC',
		$limit = null,
		$output_type = OBJECT
	) {
		$order = $this->check_order($order);
		$operator = $this->check_operator($operator);
		$format = $this->check_format($format);
		$column_custom = '';
		if (is_array($column) && array_key_exists('_custom', $column)) {
			$column_custom .= ','.implode(',', $column['_custom']);
		}
		$column = $this->check_column($column);
		$column .= $column_custom;
		$sql = "SELECT $column FROM $this->tablename ";
		if (null !== $join) {
			$sql .= "$join ";
		}
		$sql .= 'WHERE 1=1 ';
		$i = 0;
		foreach ($conditions as $field => $value) {
			if (!$value) {
				++$i;
				continue;
			}
			if (is_array($operator)) {
				if (isset($operator[$field])) {
					$op = $operator[$field];
				} elseif (isset($operator[$i])) {
					$op = $operator[$i];
				} else {
					$op = '=';
				}
			} else {
				$op = $operator;
			}
			if (is_array($format)) {
				if (isset($format[$field])) {
					$f = $format[$field];
				} elseif (isset($format[$i])) {
					$f = $format[$i];
				} else {
					$f = '%s';
				}
			} else {
				$f = $format;
			}
			$method = 'sql_'.mb_strtolower(str_replace(' ', '_', $op));
			if (method_exists($this, $method)) {
				$sql .= $this->$method($field, $value, $f, true);
			} else {
				$sql .= $this->sql_default($field, $value, $op, $f, true);
			}
			++$i;
		}
		if (null !== $orderby) {
			$orderby = $this->check_column($orderby);
			$order = null !== $order ? $this->check_order($order) : null;
			if ($orderby) {
				$sql .= " ORDER BY $orderby";
				if ($order) {
					$sql .= " $order";
				}
			}
		}
		if (null !== $limit && is_array($limit)) {
			$sql .= ' LIMIT ';
			$sql .= implode(',', $limit);
		}

		return $this->wpdb->get_results($sql, $output_type);
	}

	/**
	 * Count a table record in the table.
	 *
	 * @since  0.0.1
	 *
	 * @param int $column_offset
	 * @param int $row_offset
	 *
	 * @return int number of the count
	 */
	public function count($column_offset = 0, $row_offset = 0)
	{
		$sql = "SELECT COUNT(*) FROM $this->tablename";

		return $this->wpdb->get_var($sql, $column_offset, $row_offset);
	}

	/**
	 * count a record in the column.
	 *
	 * @since  0.0.1
	 *
	 * @param string $column Column name in table
	 *
	 * @return array $returns  Array set of counts per column
	 */
	public function count_column($column)
	{
		$output_type = ARRAY_A;
		$column = $this->check_column($column);
		$sql = "SELECT $column, COUNT(*) AS count FROM $this->tablename GROUP BY $column";
		$totals = $this->wpdb->get_results($sql, $output_type);
		$returns = [];
		$all = 0;
		foreach ($totals as $row) {
			$all += $row['count'];
			$returns[$row[$column]] = $row['count'];
		}
		$returns['all'] = $all;

		return $returns;
	}

	/**
	 * Insert data into the current data.
	 *
	 * @since  0.0.1
	 *
	 * @param array  $data   array( 'column' => 'values' ) - Data to enter into the database table
	 * @param string $format
	 *
	 * @return int The row ID
	 */
	public function insert(array $data, $format = '%s')
	{
		if (empty($data)) {
			return false;
		}
		$format = $this->check_format($format);

		$this->wpdb->insert($this->tablename, $data, $format);

		return $this->wpdb->insert_id;
	}

	/**
	 *  A method for inserting multiple rows into the specified table
	 *  Updated to include the ability to Update existing rows by primary key.
	 *
	 *  Usage Example for insert:
	 *
	 *  $insert_arrays = array();
	 *  foreach($assets as $asset) {
	 *  $time = current_time( 'mysql' );
	 *  $insert_arrays[] = array(
	 *  'type' => "multiple_row_insert",
	 *  'status' => 1,
	 *  'name'=>$asset,
	 *  'added_date' => $time,
	 *  'last_update' => $time);
	 *
	 *  }
	 *
	 *
	 *  wp_insert_rows($insert_arrays);
	 *
	 *  Usage Example for update:
	 *
	 *  wp_insert_rows($insert_arrays, true, "primary_column");
	 *
	 *
	 * @param array $row_arrays
	 * @param bool  $update
	 *
	 * @return false|int
	 *
	 * @author    Ugur Mirza ZEYREK
	 * @contributor Travis Grenell
	 * @source http://stackoverflow.com/a/12374838/1194797
	 */
	public function insert_rows($row_arrays = [], $update = false)
	{
		// Setup arrays for Actual Values, and Placeholders
		$values = [];
		$place_holders = [];
		$query = '';
		$query_columns = '';

		$query .= "INSERT INTO $this->tablename (";
		foreach ($row_arrays as $count => $row_array) {
			foreach ($row_array as $key => $value) {
				if (0 === $count) {
					if ($query_columns) {
						$query_columns .= ', '.$key.'';
					} else {
						$query_columns .= ''.$key.'';
					}
				}

				$values[] = $value;

				$symbol = '%s';
				//				if ( is_numeric( $value ) ) {
				////					$symbol = filter_var($value, FILTER_VALIDATE_FLOAT) ? '%f' : '%d';
				//					$symbol = is_float($value) ? '%f' : '%d';
				//				}

				if (isset($place_holders[$count])) {
					$place_holders[$count] .= ", '$symbol'";
				} else {
					$place_holders[$count] = "( '$symbol'";
				}
			}
			// mind closing the GAP
			$place_holders[$count] .= ')';
		}

		$query .= " $query_columns ) VALUES ";

		$query .= implode(', ', $place_holders);

		if ($update) {
			$_update = ' ON DUPLICATE KEY UPDATE ';
			$cnt = 0;
			foreach ($row_arrays[0] as $key => $value) {
				if (0 === $cnt) {
					$_update .= "$key=VALUES($key)";
					$cnt = 1;
				} else {
					$_update .= ", $key=VALUES($key)";
				}
			}
			$query .= $_update;
		}

		$sql = $this->wpdb->prepare($query, $values);

		return $this->wpdb->query($sql);
	}

	/**
	 * Update a table record in the database.
	 *
	 * @since  0.0.1
	 *
	 * @param array $data A named array of WHERE clauses (in column => value pairs).
	 *                                 Multiple clauses will be joined with ANDs. Both $where columns and $where values should be "raw".
	 * @param array $condition Key value pair for the where clause of the query.
	 *                                 Multiple clauses will be joined with ANDs. Both $where columns and $where values should be "raw".
	 * @param mixed $format
	 *
	 * @return int|bool $updated    this method returns the number of rows updated, or false if there is an error
	 */
	public function update(array $data, array $condition, $format = '%s')
	{
		if (empty($data)) {
			return false;
		}

		return $this->wpdb->update($this->tablename, $data, $condition, $format);
	}

	/**
	 * Delete row on the database table.
	 *
	 * @since  0.0.1
	 *
	 * @param array  $conditions
	 * @param string $format
	 *
	 * @return int Num rows deleted
	 *
	 * @internal param array $conditionValue - Key value pair for the where clause of the query
	 */
	public function delete(array $conditions, $format = '%s')
	{
		if (empty($conditions)) {
			return false;
		}

		$format = $this->check_format($format);

		return $this->wpdb->delete($this->tablename, $conditions, $format);
	}

	/**
	 * Delete rows on the database table.
	 *
	 * @since  0.0.1
	 *
	 * @param string $field          The table column name
	 * @param array  $conditionvalue The value to be deleted
	 * @param string $format         $wpdb->prepare() Format String
	 *
	 * @return $deleted
	 */
	public function bulk_delete($field, array $conditionvalue, $format = '%s')
	{
		$format = $this->check_format($format);
		// how many entries will we select?
		$how_many = count($conditionvalue);
		// prepare the right amount of placeholders
		// if you're looing for strings, use '%s' instead
		$placeholders = array_fill(0, $how_many, $format);
		// glue together all the placeholders...
		// $format = '%s, %s, %s, %s, %s, [...]'
		$format = implode(', ', $placeholders);
		$sql = "DELETE FROM $this->tablename WHERE $field IN ($format)";
		$sql = $this->wpdb->prepare($sql, $conditionvalue);

		return $this->wpdb->query($sql);
	}

	/**
	 * @return wpdb
	 */
	public function getWpdb()
	{
		return $this->wpdb;
	}

	/**
	 * @param null $column
	 * @param $value
	 * @param string $op
	 * @param string $format
	 * @param bool   $and
	 *
	 * @return string
	 */
	protected function sql_custom($column = null, $value, $op = '', $format = '%s', $and = true)
	{
		$sql = '';
		if (is_array($value)) {
			foreach ($value as $val) {
				$sql .= $this->_sql_and($and);
				$sql .= " $val";
			}
		}

		return $sql;
	}

	/**
	 * Append IN clause for sql query via $wpdb->prepare.
	 *
	 * @since  0.0.1
	 *
	 * @param string $column The Column Name
	 * @param array  $value  The array values for the WHERE clause
	 * @param string $format single format string for prepare
	 * @param bool   $and    before the statement prepend AND if true, prepend OR if $and === 'OR', prepend nothing if false
	 *
	 * @return string $sql       The prepared sql statement
	 */
	protected function sql_in($column, array $value, $format = '%s', $and = true)
	{
		$how_many = count($value);
		$placeholders = array_fill(0, $how_many, $format);
		$new_format = implode(', ', $placeholders);
		$sql = $this->_sql_and($and);
		$sql .= " `$column` IN ($new_format)";
		$sql = $this->wpdb->prepare($sql, $value);

		return $sql;
	}

	/**
	 * Append NOT IN clause for sql query via $wpdb->prepare.
	 *
	 * @since  0.0.1
	 *
	 * @param string $column The Column Name
	 * @param array  $value  The array values for the WHERE clause
	 * @param string $format single format string for prepare
	 * @param bool   $and    before the statement prepend AND if true, prepend OR if $and === 'OR', prepend nothing if false
	 *
	 * @return string $sql       The prepared sql statement
	 */
	protected function sql_not_in($column, array $value, $format = '%s', $and = true)
	{
		$how_many = count($value);
		$placeholders = array_fill(0, $how_many, $format);
		$new_format = implode(', ', $placeholders);
		$sql = $this->_sql_and($and);
		$sql .= " `$column` NOT IN ($new_format)";
		$sql = $this->wpdb->prepare($sql, $value);

		return $sql;
	}

	/**
	 * Append BETWEEN clause for sql query via $wpdb->prepare.
	 *
	 * @since  0.0.1
	 *
	 * @param string $column The Column Name
	 * @param array  $value  The array values for the WHERE clause
	 * @param string $format single format string for prepare
	 * @param bool   $and    before the statement prepend AND if true, prepend OR if $and === 'OR', prepend nothing if false
	 *
	 * @return string $sql       The prepared sql statement
	 *
	 * @throws Exception
	 */
	protected function sql_between($column, array $value, $format = '%s', $and = true)
	{
		if (count($value) < 2) {
			throw new Exception('Values for BETWEEN query must be more than one.', 1);
		}
		$sql = $this->_sql_and($and);
		$sql .= $this->wpdb->prepare(" `$column` BETWEEN $format AND $format", $value[0], $value[1]);

		return $sql;
	}

	/**
	 * Append NOT BETWEEN clause for sql query via $wpdb->prepare.
	 *
	 * @since  0.0.1
	 *
	 * @param string $column The Column Name
	 * @param array  $value  The array values for the WHERE clause
	 * @param string $format single format string for prepare
	 * @param bool   $and    before the statement prepend AND if true, prepend OR if $and === 'OR', prepend nothing if false
	 *
	 * @return string $sql       The prepared sql statement
	 *
	 * @throws Exception
	 */

	/** @noinspection MoreThanThreeArgumentsInspection */
	protected function sql_not_between($column, array $value, $format = '%s', $and = true)
	{
		if (count($value) < 2) {
			throw new Exception('Values for NOT BETWEEN query must be more than one.', 1);
		}
		$sql = $this->_sql_and($and);
		$sql .= $this->wpdb->prepare(" `$column` NOT BETWEEN $format AND $format", $value[0], $value[1]);

		return $sql;
	}

	/**
	 * Append LIKE clause for sql query via $wpdb->prepare.
	 *
	 * @since  0.0.1
	 *
	 * @param string $column The Column Name
	 * @param string $value  The LIKE string values for the WHERE clause
	 * @param string $format single format string for prepare
	 * @param bool   $and    before the statement prepend AND if true, prepend OR if $and === 'OR', prepend nothing if false
	 *
	 * @return string $sql       The prepared sql statement
	 */
	protected function sql_like($column, $value, $format = '%s', $and = true)
	{
		$sql = $this->_sql_and($and);
		$sql .= $this->wpdb->prepare(" `$column` LIKE $format", $value);

		return $sql;
	}

	/**
	 * Append NOT LIKE clause for sql query via $wpdb->prepare.
	 *
	 * @since  0.0.1
	 *
	 * @param string $column The Column Name
	 * @param string $value  The LIKE string values for the WHERE clause
	 * @param string $format single format string for prepare
	 * @param bool   $and    before the statement prepend AND if true, prepend OR if $and === 'OR', prepend nothing if false
	 *
	 * @return string $sql       The prepared sql statement
	 */
	protected function sql_not_like($column, $value, $format = '%s', $and = true)
	{
		$sql = $this->_sql_and($and);
		$sql .= $this->wpdb->prepare(" `$column` NOT LIKE $format", $value);

		return $sql;
	}
}
