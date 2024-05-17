# pg_model
PHP object model/listing autoloaded from PostgreSQL database supporting references (foreign keys)

https://github.com/fra-iesus/pg_model

# Example:
Let's have two db tables:
```
schema.users_table
id_user     | int       | primary key
name        | varchar
created     | timestamp
modified_by | int
modified    | timestamp
reference   | int       | foreign key schema.some_table (id)

schema.some_table
id          | int       | primary key
modified_by | int       | foreign key schema.users_table (id_user)
modified    | timestamp
value       | varchar
```

```php
require_once('./lib/pg_model.php');
$db_conn = pg_connect('host=localhost port=5432 dbname=some_db user=me ...');

$config = [
	# mandatory - db connection
	'db' => &$db_conn,
	# optional advanced options for classes
	'classes' => [
		# explicitly defined autoloaders not using foreign keys
		# (for some reason like circular references or so)
		'autoloaders'  => [
			'modified_by' => [
				'class' => 'schema.users_table',
				'keys' => [
					'id_user' => 'modified_by'
				],
				'exclude' => [
					# Do not autoload here to avoid circular reference
					'schema.some_table'
				]
			]
		],
		# values which should be set while saving the row to the database
		# unless they were explicitly updated
		# (scalar value or function could be used)
		'autoupdate' => [
			'modified' => 'NOW()',
			'modified_by'  => function () use (&$USER) {
				return "'" . $USER->id_user . "'";
			}
		]
	]
];

# load a row from the database
$USER = new Schema\Users_Table($config, [ 'id_user' => 1 ]);
print_r($USER->to_hash());
```
The output will look like this:
```
Array (
      [id_user] => 1
      [name] => some username
      [created] => 2001-01-01 00:00:00+01
      [modified_by] => 1
      [modified] => 2019-04-19 01:02:03+01
      [reference] => 1
      [schema_some_table_reference] => Array (
            [id] => 1
            [modified_by] => 1
            [modified] => 2019-04-19 01:02:03+01
            [value] => some value
      )
)
```
Object `schema_some_table_reference` was created automatically by autoloader from a foreign key on the `reference` column pointing to the `schema.some_table` table and `id` column.

```php
# Different getter/setter syntaxes are supported:
$USER->name('New Name');
$USER->name = 'New Name';
$USER('name', 'New Name');

$USER->save();

# Explicitly autoload class excluded from implicit autoloading
$some_table_row = new Schema\Some_Table($config)->get_list( ['limit' => 1] )->list[0];
$autoloaded_class = $some_table_row->autoload('modified_by');
# At this point the autoloaded class is also accessible via $some_table_row->schema_users_table_modified_by

# filters = search query, ordering and counts on other tables
$list = new Schema\Users_Table\Listing($config, [
	'limit' => 1,
	'filters' => [
		'reference' => 1,
		'name' => [
			'condition' => 'ilike',
			'value' => 'T%'
		]
	],
	'ordering' => [
		'name' => 'asc', # 'asc', 'desc' / 1, -1
	],
	'counts' => [ 'schema.some_other_table' => 'id_user' ],
] );
# count of records with the same 'id_user' is afterwards accessible in models by a property named 'schema_some_other_table'
```

# Audit logs and data transformations:
```php
...
$config = [
	...
	'classes' => [
		# values which should be transformed while saving or loading
		'autotransform' => [
			'password' => [
				'load' => function ($value) use (&$enc_key) {
					$twofish = new \phpseclib3\Crypt\Twofish('ctr');
					$parts = explode(':', $value);
					if (count($parts) > 1) {
						$twofish->setIV(base64_decode($parts[0]));
						$twofish->setKey($enc_key);
						$decoded =  $twofish->decrypt(base64_decode($parts[1]));
						return $decoded;
					}
					return $value;
				},
				'save' => function ($value) use (&$enc_key) {
					$twofish = new \phpseclib3\Crypt\Twofish('ctr');
					$iv = \phpseclib3\Crypt\Random::string(16);
					$twofish->setIV($iv);
					$twofish->setKey($enc_key);
					$encoded = base64_encode($iv) . ':' . base64_encode($twofish->encrypt($value));
					return $encoded;
				}
			]
		],
		'audit_log' => [
			'class' => 'Log\Audit', #db table log.audit
			'columns' => [
				'table' => 'table_name',
				'index' => 'ids',
				'data' => 'changes'
			]
		]
	]
];
...
```
Column `password` is decrypted using `$enc_key` when loaded from the database and encrypted when stored.
Every change to the data is logged into the db table `log.audit`.

# TODO:
- add column value validation based on its definition
- method for search and recursive removal of references to allow row deletion in case of foreign keys (quite dangerous, isn't it? The proper behaviour shall be defined for every single foreign key: eg. remove the reference setting that column to null or remove the referencing entity)
