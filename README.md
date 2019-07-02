# pg_model
PHP object model/listing autoloaded from PostgreSQL database supporting referencies (foreign keys)

# Example:
```php
require_once('./lib/pg_model.php');
$db_conn = pg_connect('host','username','password','database');

$config = array (
	'db' => &$db_conn,
	'classes' => array (
		'autoloaders'  => array (
			'modified_by' => array (
				'class' => 'schema.users_table',
				'keys' => array (
					'id' => 'modified_by'
				),
				'exclude' => array (
					'schema.some_table'
				)
			)
		),
		'defaults' => array (
			'modified' => 'NOW()',
			'modified_by'  => function () use (&$USER) {
				return "'" . $USER->id . "'";
			}
		)
	)
);

$USER = new Schema\Users_Table($config, array( 'id' => 1 ));
print_r($USER->to_hash());
$USER->name('New Name');
$USER->save();
# explicitly autoload class excluded from implicit autoloading
$some_table_row = new Schema\Some_Table($config)->get_list( array ('limit' => 1) )->list[0];
$some_table_row->autoload('modified_by');
```
# TODO:
- add column value validation based on its definition
