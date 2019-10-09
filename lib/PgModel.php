<?php
class PgModel {
	var $definition = array (
		'schema'        => '',
		'table'         => '',
		'columns'       => array (),
		'keys'          => array (),
		'autoloaders'   => array (),
		'autoincrement' => null # TODO
	);
	var $values     = array ();
	var $old_values = array ();

	protected $loaded_classes = array ();
	protected $_loaded        = false;
	protected $_changed       = false;
	protected $c              = null;

	function __construct(&$config, $table_name, $values = null) {
		$this->c = &$config;
		$this->set_class($table_name);
		$this->init_struct();

		if ($values) {
			$this->parse_params($values);
			$this->load();
		}
	}

	function is_loaded() {
		return $this->_loaded;
	}

	function load($force = false) {
		if ( (!$force && $this->_loaded) || !sizeof($this->definition['keys']) ) {
			if (!sizeof($this->definition['keys'])) {
				error_log("WARN: keys not defined for class {$this->get_class()}");
			} else {
				error_log("WARN: class {$this->get_class()} already loaded");
			}
			return;
		}
		$keys_values = join('_', $this->keys_values());

		if (!array_key_exists('loaded_classes', $this->c['classes'])) {
			$this->c['classes']['loaded_classes'] = array ();
		}
		if (array_key_exists($this->get_class(), $this->c['classes']['loaded_classes']) && array_key_exists($keys_values, $this->c['classes']['loaded_classes'][$this->get_class()])) {
			$this->values = $this->c['classes']['loaded_classes'][$this->get_class()][$keys_values];
			$this->_loaded = true;
			return true;
		}

		$this->_loaded = false;

		$query = "SELECT * FROM {$this->get_class()} WHERE ";
		$vals = array();
		foreach ($this->definition['keys'] as $key => $value) {
			$vals[] = $key . ' = ' . $this->prepare_value($key);
		}
		$query .= implode(' AND ', $vals);
		$this->load_by_query($query, $key);

		$this->c['classes']['loaded_classes'][$this->get_class()][$keys_values] = $this->values;

		return $this->_loaded;
	}

	function save() {
		$params = $this->all_values();
		if (!$this->_changed) {
			return true;
		}
		if (!$this->_loaded) {
			$query = "INSERT INTO {$this->get_class()} (" . implode(', ', $this->columns()). ") VALUES (";
			$vals = array();
			foreach ($this->definition['columns'] as $key => $value) {
				$vals[] = $this->prepare_value($key);
			}
			$query .= implode(', ', $vals);
			$query .= ')';
		} else {
			$query = "UPDATE {$this->get_class()} SET ";
			$vals = array();
			foreach ($this->definition['columns'] as $key => $value) {
				$vals[] = $key . ' = ' . $this->prepare_value($key);
			}
			$query .= implode(', ', $vals);
			$query .= ' WHERE ';

			$vals = array();
			foreach ($this->definition['keys'] as $key => $value) {
				$vals[] = $key . ' = ' . $this->prepare_value($key, true);
			}
			$query .= implode(' AND ', $vals);
		}
		if ($res = pg_query($this->c['db'],$query)) {
			if (join('_', $this->keys_values(true)) != join('_', $this->keys_values())) {
				unset($this->c['classes']['loaded_classes'][$this->get_class()][join('_', $this->keys_values(true))]);
			}
			$this->old_values = array ();
			foreach ($this->definition['columns'] as $key => $value) {
				$this->definition['columns'][$key]['saved'] = true;
				$this->definition['columns'][$key]['loaded'] = true;
			}
			$this->_changed = false;
			foreach ($this->definition['autoloaders'] as $key => $class) {
				if ($this->$key) {
					$this->$key->save();
				}
			}
		}
		return $res;
	}

	function delete() {
		if (!$this->_loaded) {
			return false;
		}
		$query = "DELETE FROM {$this->get_class()} WHERE ";
		$vals = array();
		foreach ($this->definition['keys'] as $key => $value) {
			$vals[] = $key . ' = ' . $this->prepare_value($key);
		}
		$query .= implode(' AND ', $vals);
		if (!pg_query($this->c['db'],$query)) {
			return false;
		}
		$this->loaded_classes = array ();
		$this->values = array();
		$this->_loaded = false;
		return true;
	}

	function changed_columns() {
		$changed = array();
		foreach ($this->definition['columns'] as $key => $value) {
			if (!$this->definition['columns'][$key]['saved']) {
				$changed[$key] = $this->old_values[$key];
			}
		}
		return $changed;
	}

	function get_class() {
		return $this->definition['schema'] . '.' . $this->definition['table'];
	}

	function parse_all($params) {
		foreach ($params as $key => $value) {
			if (!is_array($value)) {
				$this->$key($value);
			}
		}
		return $this;
	}

	function to_hash($deep = 0) {
		if ($deep > 16) {
			return;
		}
		$hash = array();
		foreach ($this->definition['columns'] as $key => $value) {
			$hash[$key] = $this->values[$key];
		}
		foreach ($this->definition['autoloaders'] as $key => $class) {
			if ($this->$key) {
				$hash[$key] = $this->$key->to_hash($deep + 1);
			}
		}
		return $hash;
	}

	function autoload($autoloader) {
		if (is_array($this->c['classes']['autoloaders']) && array_key_exists($autoloader, $this->c['classes']['autoloaders']) && array_key_exists($autoloader, $this->definition['columns'])) {
			$value = $this->c['classes']['autoloaders'][$autoloader];
			$this->definition['autoloaders'][str_replace('.', '_', $value['class']) . '_' . $autoloader] = $value;
			return $this->__get(str_replace('.', '_', $value['class']) . '_' . $autoloader);
		}
		return false;
	}

	function upload($column, $file, $type = 'bytea') {
		if ($file['error'] == UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
			$this->$column(file_get_contents($file['tmp_name']), $type);
		}
	}

	function map_data_type($type) {
		$types = array (
			'boolean' => 'bool',
			'integer' => 'int4',
			'double'  => 'numeric',
			'string'  => 'varchar',
			'array'   => 'varchar', # TODO ?
		);
		if (array_key_exists($type, $types)) {
			return $types[$type];
		}
		return 'varchar';
	}

	function parse_params($params) {
		foreach ( $this->definition['columns'] as $key => $val ) {
			if (isset($params[$key])) {
				$this->$key = $params[$key];
			} else {
				$this->$key = '';
			}
		}
	}

	function promise_is_loaded() {
		return $this->_loaded = true;
	}

	function get_list($params) {
		if (!array_key_exists('offset', $params)) {
			$params['offset'] = null;
		}
		if (!array_key_exists('limit', $params)) {
			$params['limit'] = null;
		}
		if (!array_key_exists('current', $params)) {
			$params['current'] = null;
		}
		if (!array_key_exists('filters', $params)) {
			$params['filters'] = null;
		}
		$params['class'] = $this->get_class();
		$listing = new PgListing($this->c, $params);
		return $listing;
	}

	public function __invoke() {
		if (func_num_args() == 1) {
			return $this->values[func_get_arg(0)];
		} else {
			$name = func_get_arg(0);
			$value = func_get_arg(1);
			return $this->$name($value);
		}
	}

	public function __set($name, $value) {
		$this->$name($value);
	}

	public function __call($name, $arguments) {
		if ( array_key_exists($name, $this->definition['columns']) ) {
			if ($this->values[$name] != $arguments[0]) {
				$this->definition['columns'][$name]['saved'] = false;
				$this->_changed = true;
			} else {
				return $this->values[$name];
			}
		} else {
			$this->definition['columns'][$name] = array (
				'required'   => false,
				'type'       => $arguments[1] ? $arguments[1] : $this->map_data_type($arguments[0]),
				'references' => array (),
				'saved'      => false,
				'loaded'     => false
			);
			$this->_changed = true;
		}
		foreach ($this->definition['columns'][$name]['references'] as $key => $value) {
	 		unset($this->loaded_classes[$value]);
		}
		$this->old_values[$name] = $this->values[$name];
		$this->values[$name] = $arguments[0];
		return $this->values[$name];
	}

	public function __get($name) {
		if ( array_key_exists($name, $this->definition['autoloaders']) ) {
			if (!array_key_exists($name, $this->loaded_classes)) {
				$class = $this->definition['autoloaders'][$name];
				$class_name = $class['class'];
				$loaded_class = new PgModel($this->c, $class_name);
				$do_not_load = false;
				foreach ($class['keys'] as $key => $value) {
					$loaded_class->$key = $this->$value;
					if (!isset($this->$value)) {
						$do_not_load = true;
					}
				}
				if (!$do_not_load) {
					$loaded_class->load();
					$this->loaded_classes[$name] = $loaded_class;
				}
			}
			return $this->loaded_classes[$name];
		}
		if ( array_key_exists($name, $this->definition['columns']) ) {
			return $this->values[$name];
		} else {
			error_log("ERROR: unknown method/variable '$name' in class {$this->get_class()}");
		}
		return;
	}

	public function __isset($name) {
		return array_key_exists($name, $this->values);
	}

	public function __unset($name) {
		$this->old_values[$name] = $this->values[$name];
		unset($this->values[$name]);
	}

	public function __toString() {
		return $this->get_class();
	}

	# ==================================================================================================

	function set_class($class) {
		$class = strtolower($class);
		$class = explode('.', $class);
		if (sizeof($class) == 1) {
			array_unshift($class, 'public');
			error_log("WARN: schema not defined for class {$class}, 'public' will be used!");
		}
		$this->definition['schema'] = $class[0];
		$this->definition['table'] = $class[1];
		return $this;
	}

	protected function load_by_query($load_query) {
		if ($res = pg_query($this->c['db'], $load_query)) {
			if ($values = pg_fetch_assoc($res)) {
				$this->old_values = array ();
				$this->values = array ();
				$this->loaded_classes = array ();
				foreach ($values as $key => $value) {
					$key = trim($key);
					$this->$key($value);
					$this->definition['columns'][$key]['saved'] = true;
					$this->definition['columns'][$key]['loaded'] = true;
				}
				$this->_changed = false;
				$this->_loaded = true;
			}
		}
		return true;
	}


	protected function assign_keys() {
		$args = func_get_args();
		foreach ( $this->definition['keys'] as $key => $value) {
			$this->values[$key] = array_shift($args);
			$this->definition['columns'][$key]['saved'] = false;
		}
		$this->_changed = true;
	}

	protected function columns() {
		$columns = array();
		foreach ( $this->definition['columns'] as $column => $val ) {
			array_push($columns, $column);
		}
		return $columns;
	}

	protected function prepare_value($column, $old_value = false) {
		$value = null;
		if ($this->c['classes']['defaults'][$column]) {
			if (is_callable($this->c['classes']['defaults'][$column])) {
				return $this->c['classes']['defaults'][$column]();
			}
			return $this->c['classes']['defaults'][$column];
		}
		if (null !== ($old_value ? $this->old_values[$column] : $this->values[$column])) {
			$value = $old_value ? $this->old_values[$column] : $this->values[$column];
			switch ($this->definition['columns'][$column]['type']) {
				case 'int2':
				case 'int4':
				case 'int8':
					$value = "'".(int)$value."'";
					break;
				case 'numeric':
					$value = "'".(float)$value."'";
					break;
				case 'bool':
					$value = (bool)$value;
					break;
				case 'bytea':
					$value = pg_escape_bytea($this->c['db'], $value);
					break;
				# timestamptz, text, varchar
				default:
					$value = "'".pg_escape_string($this->c['db'],(string)$value)."'";
			}
		} elseif (isset($this->definition['columns'][$column]['default'])) {
			$value = $this->definition['columns'][$column]['default'];
		} else {
			$value = 'NULL';
		}
		return $value;
	}

	protected function keys_values($old = false) {
		$values = array();
		foreach ($this->definition['keys'] as $key => $value) {
			array_push($values, $this->prepare_value($key, $old));
		}
		return $values;
	}

	protected function all_values() {
		$values = array();
		foreach ($this->columns() as $key => $value) {
			if (!isset($this->values[$key]) && $this->definition['columns'][$key]['required']) {
				# die("Missing required value for column $key in table ".$this->definition['table']);
			} else {
				array_push($values, $this->prepare_value($key));
			}
		}
		return $values;
	}

	protected function init_struct() {
		if (!$this->c) {
			die(error_log("ERROR: config for class {$this->get_class()} not defined!"));
		}
		if (!$this->c['db']) {
			die(error_log("ERROR: database connection for class {$this->get_class()} not defined!"));
		}
		if (!array_key_exists('classes', $this->c)) {
			$this->c['classes'] = array ();
		}
		if (!array_key_exists('definitions', $this->c['classes'])) {
			$this->c['classes']['definitions'] = array ();
		}
		if (array_key_exists($this->get_class(), $this->c['classes']['definitions'])) {
			$this->definition = $this->c['classes']['definitions'][$this->get_class()];
			$this->values = array ();
			$this->old_values = array ();
			$this->loaded_classes = array ();
			return true;
		}

		$schema = pg_escape_string($this->definition['schema']);
		$table = pg_escape_string($this->definition['table']);
		# get basic table definition
		$query = "
			SELECT c.column_name, c.ordinal_position, c.column_default, c.is_nullable, c.data_type, c.character_maximum_length, c.udt_name, i.indisunique AS is_unique, i.indisprimary AS is_primary
			FROM information_schema.columns c
			LEFT JOIN pg_attribute a ON a.attrelid = '{$schema}.{$table}'::regclass AND a.attname = c.column_name
			LEFT JOIN pg_index i ON i.indrelid = a.attrelid AND a.attnum = any(i.indkey) AND i.indisprimary
			WHERE c.table_schema = '{$schema}' AND c.table_name = '{$table}'
			ORDER BY c.ordinal_position
		";
		if ( ($res = pg_query($this->c['db'], $query)) && pg_num_rows($res) ) {
			$this->definition['columns'] = array ();
			$this->definition['keys'] = array ();
			$this->definition['autoloaders'] = array ();
			$this->values = array ();
			$this->old_values = array ();
			$this->loaded_classes = array ();

			while ($row = pg_fetch_assoc($res)) {
				$this->definition['columns'][trim($row['column_name'])] = array (
					'required'   => !$row['is_nullable'],
					'type'       => $row['udt_name'],
					'length'     => $row['character_maximum_length'],
					'default'    => $row['column_default'],
					'position'   => $row['ordinal_position'],
					'unique'     => $row['is_unique'],
					'primary'    => $row['is_primary'],
					'references' => array (),
					'saved'      => false,
					'loaded'     => false
				);
				$this->_changed = true;
				if ($row['is_primary']) {
					$this->definition['keys'][trim($row['column_name'])] = null;
				}
			}

			# get autoloaders definition
			$query = "
				SELECT conname, pg_catalog.pg_get_constraintdef(r.oid, true) as condef
				FROM pg_catalog.pg_constraint r
				WHERE r.conrelid = '{$schema}.{$table}'::regclass AND r.contype = 'f'
			";
			if ( $res = pg_query($this->c['db'], $query) ) {
				while ($row = pg_fetch_assoc($res)) {
					if ( preg_match('/FOREIGN KEY \(([^\)]*)\) REFERENCES ([^\(]*)\(([^\)]*)\)/', $row['condef'], $foreign) ) {
						$autoloader = str_replace('.', '_', $foreign[2]) . '_' . $foreign[1];
						if ($this->c['classes']['autoloaders'][$foreign[1]]['exclude'] && array_search($this->get_class(), $this->c['classes']['autoloaders'][$foreign[1]]['exclude']) !== false) {
							continue;
						} else {
							$this->definition['autoloaders'][$autoloader] = array (
								'class' => $foreign[2],
								'keys'  => array ()
							);
							$src_keys = explode(',', $foreign[1]);
							$dst_keys = explode(',', $foreign[3]);
							foreach ($src_keys as $key => $value) {
								$this->definition['columns'][trim($value)]['references'][] = $foreign[2];
								$this->definition['autoloaders'][$autoloader]['keys'][trim($dst_keys[trim($key)])] = trim($value);
							}
						}
					}
				}
			}
			if (is_array($this->c['classes']['autoloaders'])) {
				foreach ($this->c['classes']['autoloaders'] as $key => $value) {
					if ($value['class'] != $this->get_class() && array_search($this->get_class(), $value['exclude']) === false && array_key_exists($key, $this->definition['columns'])) {
						$this->definition['autoloaders'][str_replace('.', '_', $value['class']) . '_' . $key] = $value;
					}
				}
			}
			$this->c['classes']['definitions'][$this->get_class()] = $this->definition;
			return true;
		} else {
			error_log("ERROR: unable to init class {$this->get_class()}!");
		}
		return false;
	}

}

?>