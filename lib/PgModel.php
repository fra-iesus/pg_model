<?php

class PgModel {
	var $definition = [
		'pg_class'      => '',
		'schema'        => '',
		'table'         => '',
		'columns'       => [],
		'keys'          => [],
		'autoloaders'   => [],
		'autoincrement' => null # TODO
	];
	var $values     = [];
	var $old_values = [];
	var $properties = [];

	protected $loaded_classes = [];
	protected $_loaded        = false;
	protected $_changed       = false;
	protected $c              = null;

	function __construct(&$config, $values = null) {
		$this->c = &$config;

		$class = get_called_class();
		$this->definition['pg_class'] = $class;
		$table_name = str_replace('\\', '.', $class);

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
		if ( (!$force && $this->_loaded) || !count($this->definition['keys']) ) {
			if (!count($this->definition['keys'])) {
				error_log("WARN: keys not defined for class {$this->get_class()}\n");
			} else {
				error_log("WARN: class {$this->get_class()} already loaded\n");
			}
			return;
		}
		$keys_values = join('_', $this->keys_values(false, false));

		if (!array_key_exists('loaded_classes', $this->c['classes'])) {
			$this->c['classes']['loaded_classes'] = [];
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
			$vals[] = $key . ' = ' . $this->prepare_value($key, false, false);
		}
		$query .= implode(' AND ', $vals);
		$this->load_by_query($query);

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
			foreach ($this->definition['columns'] as $column => $value) {
				$vals[] = $this->prepare_value($column, false, true);
			}
			$query .= implode(', ', $vals);
			$query .= ')';
		} else {
			$query = "UPDATE {$this->get_class()} SET ";
			$vals = array();
			foreach ($this->definition['columns'] as $column => $value) {
				$vals[] = $column . ' = ' . $this->prepare_value($column, false, true);
			}
			$query .= implode(', ', $vals);
			$query .= ' WHERE ';

			$vals = array();
			foreach ($this->definition['keys'] as $key => $value) {
				$vals[] = $key . ' = ' . $this->prepare_value($key, true, false);
			}
			$query .= implode(' AND ', $vals);
		}
		$query .= ' RETURNING *';
		if ($res = pg_query($this->c['db'],$query)) {
			if ($values = pg_fetch_assoc($res)) {
				foreach ($values as $column => $value) {
					$column = trim($column);
					if ($this->definition['columns'][$column]['type'] == 'bool') {
						if ($value == 't') {
							$value = 1;
						} else {
							$value = 0;
						}
					}
					$this->$column($value);
					$this->definition['columns'][$column]['saved'] = true;
					$this->definition['columns'][$column]['loaded'] = true;
				}
			}
			if (join('_', $this->keys_values(true, false)) != join('_', $this->keys_values(false, false))) {
				unset($this->c['classes']['loaded_classes'][$this->get_class()][join('_', $this->keys_values(true, false))]);
				# update in loaded classes ?
				$this->c['classes']['loaded_classes'][$this->get_class()][$keys_values] = $this->values;
			}
			$this->old_values = [];
			$this->_changed = false;
			$this->_loaded = true;
			foreach ($this->definition['autoloaders'] as $autoloader => $class) {
				// TODO: is the condition correct?
				if ($this->$autoloader && $this->$autoloader->is_loaded()) {
					$this->$autoloader->save();
				}
			}
		} elseif (isset($this->c['debug'])) {
			throw new Exception("SQL query for class {$this->get_class()} failed: " . pg_last_error($this->c['db']) . "; SQL query: $query");
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
			$vals[] = $key . ' = ' . $this->prepare_value($key, true, false);
		}
		$query .= implode(' AND ', $vals);
		if (!pg_query($this->c['db'],$query)) {
			return false;
		}
		$this->loaded_classes = [];
		$this->values = array();
		$this->_loaded = false;
		return true;
	}

	function changed_columns() {
		$changed = array();
		foreach ($this->definition['columns'] as $column => $value) {
			if (!$this->definition['columns'][$column]['saved']) {
				$changed[$column] = $this->old_values[$column];
			}
		}
		return $changed;
	}

	function get_class() {
		return $this->definition['schema'] . '.' . $this->definition['table'];
	}

	function parse_all($params) {
		foreach ($params as $column => $value) {
			if (!is_array($value)) {
				$this->$column($value);
			}
		}
		return $this;
	}

	function to_hash($deep = 0) {
		if ($deep > 16) {
			return;
		}
		$hash = array();
		foreach ($this->definition['columns'] as $columns => $value) {
			$hash[$columns] = $this->values[$columns];
		}
		foreach ($this->definition['autoloaders'] as $autoloader => $class) {
			if ($this->$autoloader) {
				$hash[$autoloader] = $this->$autoloader->to_hash($deep + 1);
			}
		}
		foreach ($this->properties as $property => $value) {
			$hash[$property] = $value;
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
		$types = [
			'boolean' => 'bool',
			'integer' => 'int8',
			'double'  => 'numeric',
			'string'  => 'varchar',
			'array'   => 'varchar', # TODO ?
			'json'    => 'json',
			'jsonb'   => 'jsonb',
		];
		if (array_key_exists($type, $types)) {
			return $types[$type];
		}
		return 'varchar';
	}

	function parse_params($params) {
		foreach ( $this->definition['columns'] as $column => $val ) {
			if (array_key_exists($column, $params)) {
				$value = $params[$column];
				if (isset($value)) {
					switch ($this->definition['columns'][$column]['type']) {
						case 'bool':
							$value = ($value == 't' ? 1 : 0);
							break;
						case 'json':
						case 'jsonb':
							$value = json_decode($value, true);
							break;
						default:
					}
					$this->$column = $value;
				} else {
					$this->$column = null;
				}
			} else {
				$this->$column = null;
			}
		}
		foreach ( $this->properties as $property => $val ) {
			if (array_key_exists($property, $params)) {
				$this->properties[$property] = $params[$property];
			}
		}
	}

	function promise_is_loaded() {
		foreach ( $this->definition['columns'] as $column => $val ) {
			$this->definition['columns'][$column]['saved'] = true;
			$this->definition['columns'][$column]['loaded'] = true;
		}
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
		$class = '\\' . $this->definition['pg_class'];
		$listing = new $class($this->c, $params);
		return $listing;
	}

	function add_property($property) {
		if (!array_key_exists($property, $this->properties)) {
			$this->properties[$property] = null;
		}
		return true;
	}

	public function __invoke() {
		if (func_num_args() == 1) {
			return $this->$name();
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
			if (!array_key_exists($name, $this->values) || $this->values[$name] !== $arguments[0]) {
				if (array_key_exists($name, $this->values)) {
					$this->old_values[$name] = $this->values[$name];
				}
				$this->values[$name] = $arguments[0];
				$this->definition['columns'][$name]['saved'] = false;
				$this->_changed = true;
			} else {
				return $this->values[$name];
			}
		} elseif (array_key_exists($name, $this->properties)) {
			return $this->properties[$name];
		} else {
			$this->definition['columns'][$name] = [
				'required'   => false,
				'type'       => $arguments[1] ? $arguments[1] : $this->map_data_type($arguments[0]),
				'references' => [],
				'saved'      => false,
				'loaded'     => false
			];
			$this->_changed = true;
		}
		foreach ($this->definition['columns'][$name]['references'] as $reference => $value) {
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
				$parts = explode('.', $class_name);
				foreach ($parts as $idx => $value) {
					$parts[$idx] = ucfirst($value);
				}
				$class_name = '\\' . join('\\', $parts);
				$loaded_class = new $class_name($this->c);
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
		if (method_exists(get_called_class(), $name)) {
			return $this->$name();
		}
		if ( array_key_exists($name, $this->definition['columns']) ) {
			return $this->values[$name];
		} elseif (array_key_exists($name, $this->properties)) {
			return $this->properties[$name];
		} else {
			throw new Exception("Unknown method/variable '$name' in class {$this->get_class()}");
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
		if (count($class) == 1) {
			array_unshift($class, 'public');
			error_log("WARN: schema not defined for class {$class}, 'public' will be used!\n");
		}
		$this->definition['schema'] = $class[0];
		$this->definition['table'] = $class[1];
		return $this;
	}

	protected function load_by_query($load_query) {
		if ($res = pg_query($this->c['db'], $load_query)) {
			if ($values = pg_fetch_assoc($res)) {
				$this->old_values = [];
				$this->values = [];
				$this->loaded_classes = [];
				foreach ($values as $column => $value) {
					$column = trim($column);
					switch ($this->definition['columns'][$column]['type']) {
						case 'bool':
							$value = ($value == 't' ? 1 : 0);
							break;
						case 'json':
						case 'jsonb':
							$value = json_decode($value, true);
							break;
						default:
					}
					$this->$column($value);
					$this->definition['columns'][$column]['saved'] = true;
					$this->definition['columns'][$column]['loaded'] = true;
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
		foreach ( $this->definition['columns'] as $column => $value ) {
			array_push($columns, $column);
		}
		return $columns;
	}

	protected function prepare_value($column, $old_value = false, $defaults = true) {
		$value = null;
		if (
				array_key_exists('classes', $this->c) &&
				array_key_exists('autoupdate', $this->c['classes']) &&
				array_key_exists($column, $this->c['classes']['autoupdate']) &&
				$this->c['classes']['autoupdate'][$column] &&
				( $this->definition['columns'][$column]['saved'] || !$this->is_loaded())
		) {
			if (is_callable($this->c['classes']['autoupdate'][$column])) {
				return $this->c['classes']['autoupdate'][$column]();
			}
			return $this->c['classes']['autoupdate'][$column];
		}
		if (null !== ($old_value && (array_key_exists($column, $this->definition['columns']) && !$this->definition['columns'][$column]['saved']) ? $this->old_values[$column] : $this->values[$column])) {
			$value = $old_value && (array_key_exists($column, $this->definition['columns']) && !$this->definition['columns'][$column]['saved']) ? $this->old_values[$column] : $this->values[$column];
			switch ($this->definition['columns'][$column]['type']) {
				case 'int2':
				case 'int4':
				case 'int8':
					$value = isset($value) ? "'".(int)$value."'" : 'NULL';
					break;
				case 'numeric':
					$value = isset($value) ? "'".(int)$value."'" : 'NULL';
					break;
				case 'bool':
					if ($value == 't' || $value == 'f') {
						$value = "'$value'";
					} elseif (isset($value)) {
						$value = (bool)$value ? 'true' : 'false';
					} else {
						$value = 'NULL';
					}
					break;
				case 'bytea':
					$value = isset($value) ? pg_escape_bytea($this->c['db'], $value) : 'NULL';
					break;
				case 'json':
				case 'jsonb':
					$value = isset($value) ? "'" . pg_escape_string($this->c['db'], json_encode($value)) . "'" : 'NULL';
					break;
				# timestamptz, text, varchar
				default:
					$value = isset($value) ? "'".pg_escape_string($this->c['db'],(string)$value)."'" : 'NULL';
			}
		} elseif (isset($this->definition['columns'][$column]['default']) && $defaults) {
			$value = $this->definition['columns'][$column]['default'];
		} else {
			$value = 'NULL';
		}
		return $value;
	}

	protected function keys_values($old = false, $defaults = false) {
		$values = array();
		foreach ($this->definition['keys'] as $key => $value) {
			array_push($values, $this->prepare_value($key, $old, $defaults));
		}
		return $values;
	}

	protected function all_values() {
		$values = array();
		foreach ($this->columns() as $column) {
			if (!isset($this->values[$column]) && $this->definition['columns'][$column]['required']) {
				# die("Missing required value for column $column in table ".$this->definition['table']);
			} else {
				array_push($values, $this->prepare_value($column));
			}
		}
		return $values;
	}

	protected function init_struct() {
		if (!$this->c) {
			throw new Exception("Config for class {$this->get_class()} not defined!");
		}
		if (!$this->c['db']) {
			throw new Exception("Database connection for class {$this->get_class()} not defined!");
		}
		if (!array_key_exists('classes', $this->c)) {
			$this->c['classes'] = [];
		}
		if (!array_key_exists('definitions', $this->c['classes'])) {
			$this->c['classes']['definitions'] = [];
		}
		if (array_key_exists($this->get_class(), $this->c['classes']['definitions'])) {
			$this->definition = $this->c['classes']['definitions'][$this->get_class()];
			$this->values = [];
			$this->old_values = [];
			$this->loaded_classes = [];
			foreach ($this->definition['columns'] as $column => $val) {
				$this->values[$column] = null;
				$this->old_values[$column] = null;
			}
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
			$this->definition['columns'] = [];
			$this->definition['keys'] = [];
			$this->definition['autoloaders'] = [];
			$this->values = [];
			$this->old_values = [];
			$this->loaded_classes = [];

			while ($row = pg_fetch_assoc($res)) {
				$column = trim($row['column_name']);
				$this->definition['columns'][$column] = [
					'required'   => !$row['is_nullable'],
					'type'       => $row['udt_name'],
					'length'     => $row['character_maximum_length'],
					'default'    => $row['column_default'],
					'position'   => $row['ordinal_position'],
					'unique'     => $row['is_unique'],
					'primary'    => $row['is_primary'],
					'references' => [],
					'saved'      => false,
					'loaded'     => false
				];
				$this->values[$column] = null;
				$this->old_values[$column] = null;
				$this->_changed = true;
				if ($row['is_primary']) {
					$this->definition['keys'][$column] = null;
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
						$foreign[1] = str_replace('"', '', $foreign[1]);
						$foreign[2] = str_replace('"', '', $foreign[2]);
						$autoloader = str_replace('.', '_', $foreign[2]) . '_' . $foreign[1];

						if (
								array_key_exists('classes', $this->c) &&
								array_key_exists('autoloaders', $this->c['classes']) &&
								array_key_exists($foreign[1], $this->c['classes']['autoloaders']) &&
								array_key_exists('exclude', $this->c['classes']['autoloaders'][$foreign[1]]) &&
								$this->c['classes']['autoloaders'][$foreign[1]]['exclude'] &&
								array_search($this->get_class(), $this->c['classes']['autoloaders'][$foreign[1]]['exclude']) !== false
						) {
							continue;
						} else {
							$this->definition['autoloaders'][$autoloader] = [
								'class' => $foreign[2],
								'keys'  => []
							];
							$src_keys = explode(',', $foreign[1]);
							$dst_keys = explode(',', $foreign[3]);
							foreach ($src_keys as $key => $value) {
								$this->definition['columns'][trim($value)]['references'][] = $foreign[2];
								$this->definition['autoloaders'][$autoloader]['keys'][trim($dst_keys[trim($key)])] = trim($value);
							}
						}
					}
				}
			} elseif (isset($this->c['debug'])) {
				throw new Exception("SQL query for class {$this->get_class()} failed:\n" . pg_last_error($this->c['db']) . ";\nSQL query:\n$query");
			}
			if (is_array($this->c['classes']['autoloaders'])) {
				foreach ($this->c['classes']['autoloaders'] as $autoloader => $value) {
					if ($value['class'] != $this->get_class() && array_search($this->get_class(), $value['exclude']) === false && array_key_exists($autoloader, $this->definition['columns'])) {
						$this->definition['autoloaders'][str_replace('.', '_', $value['class']) . '_' . $autoloader] = $value;
					}
				}
			}
			$this->c['classes']['definitions'][$this->get_class()] = $this->definition;
			return true;
		} else {
			throw new Exception("Unable to init class {$this->get_class()}!");
		}
		return false;
	}

}

?>
