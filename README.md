# pg_model
PHP object model/listing autoloaded from PostgreSQL database supporting referencies (foreign keys)

# Example:
```php
require_once('./lib/pg_model.php');
$db_conn = pg_connect('host','username','password','database');

$config = array (
	# mandatory - db connection
	'db' => &$db_conn,
	# oprtional advanced options for classes
	'classes' => array (
		# explicitly defined autoloaders not using foreign keys
		# (for some reason like circular referencies or so)
		'autoloaders'  => array (
			'modified_by' => array (
				'class' => 'schema.users_table',
				'keys' => array (
					'id' => 'modified_by'
				),
				'exclude' => array (
					# do not autoload here to avoid circular reference
					'schema.some_table'
				)
			)
		),
		# values which should be set while saving the row to the database
		# (scalar value or function could be used)
		'defaults' => array (
			'modified' => 'NOW()',
			'modified_by'  => function () use (&$USER) {
				return "'" . $USER->id . "'";
			}
		)
	)
);

# load a row from the database
$USER = new Schema\Users_Table($config, array( 'id' => 1 ));
print_r($USER->to_hash());
```
Output will look like:
```
Array (
      [id] => 1
      [name] => some username
      [created] => 2001-01-01 00:00:00+01
      [modified_by] => 1
      [modified] => 2019-04-19 01:02:03+01
      [email] => some.username@example.com
      [locale] => en_US
      [timezone] => Europe/Prague
      [reference] => 1
      [schema_some_table_reference] => Array (
            [id] => 1
            [modified_by] => 1
            [modified] => 2019-04-19 01:02:03+01
            [value] => some value
      )
)
```
Object `schema_some_table_reference` was created automatically by autoloader from foreign key on `reference` column pointing to `schema.some_table` table and `id` column.

```php
# different getter/setter syntaxes are supported:
$USER->name('New Name');
$USER->name = 'New Name';
$USER('name', 'New Name');

$USER->save();

# explicitly autoload class excluded from implicit autoloading
$some_table_row = new Schema\Some_Table($config)->get_list( array ('limit' => 1) )->list[0];
$autoloaded_class = $some_table_row->autoload('modified_by');
# at this point that autoloaded class is also accessible via $some_table_row->schema_users_table_modified_by
```

# TODO:
- add column value validation based on its definition
