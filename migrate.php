#!/usr/bin/php
<?php

/**
 * Class Migration
 * @author  Alex Shilkin <shilkin.alexander@gmail.com>
 */
class Migration
{
	public
		$id,
		$appliedAt,
		$description,
		$sqlFile,
		$applied;


	/**
	 * @param string      $id
	 * @param string      $sqlFile
	 * @param string      $description
	 * @param string|null $appliedAt
	 * @param bool        $applied
	 */
	public function __construct($id, $sqlFile, $description, $appliedAt=null, $applied=false)
	{
		$this->id = $id;
		$this->sqlFile = $sqlFile;
		$this->description = $description;
		$this->appliedAt = $appliedAt === null ? date('Y-m-d H:i:s') : $appliedAt;
		$this->applied = $applied;
	}

	/**
	 * @return string
	 */
	public function getSqlUp()
	{
		return $this->getSqlPart($undo=false);
	}

	/**
	 * @return string
	 */
	public function getSqlDown()
	{
		return $this->getSqlPart($undo=true);
	}

	/**
	 * @param  bool $undo
	 *
	 * @return string
	 */
	private function getSqlPart($undo=false)
	{
		$sql_parts = explode('@UNDO', file_get_contents($this->sqlFile));
		if (! $undo) {
			return $sql_parts[0];
		}

		return isset($sql_parts[1]) ? $sql_parts[1] : '';
	}
}


/**
 * Class Migrate
 * @author  Alex Shilkin <shilkin.alexander@gmail.com>
 */
class Migrate
{
	const
		ACTION_STATUS   = 'status',
		ACTION_GENERATE = 'generate',
		ACTION_UP       = 'up',
		ACTION_DOWN     = 'down',
		ACTION_HELP     = 'help',
		ACTION_INIT     = 'init',
		ACTION_FORCE    = 'force';

	// modes
	const MODE_VERBOSE = 'verbose';

	/** @var mysqli */
	private $db;
	private $config = [];
	private $options = [];
	private $action = self::ACTION_HELP;
	private $migration_path = '';

	private $opts = [
		'generate::',
		'status::',
		'up::',
		'down::',
		'force::',
		'init::',
		'verbose::',
	];


	public function __construct()
	{
		$this->migration_path = __DIR__ . '/schema';
		$this->config = include __DIR__ . '/config.php';
		$this->options = getopt('', $this->opts);
		$this->action = $this->parseAction($this->options);
	}

	/**
	 * @return string
	 */
	public function run()
	{
		if ($this->action == self::ACTION_STATUS) {
			$this->connect();

			return $this->doStatus();
		}

		if ($this->action == self::ACTION_INIT) {
			$this->connect();

			return $this->doInit();
		}

		if ($this->action == self::ACTION_GENERATE) {
			return $this->doGenerate($this->options[self::ACTION_GENERATE]);
		}

		if ($this->action == self::ACTION_UP) {
			$this->connect();
			$migrationId = isset($this->options[self::ACTION_UP]) ? $this->options[self::ACTION_UP] : 0;

			if (isset($this->options[self::ACTION_FORCE])) {
				return $this->doUpForce($migrationId);
			}

			return $this->doUp($migrationId);
		}

		if ($this->action == self::ACTION_DOWN) {
			$this->connect();
			$migrationId = isset($this->options[self::ACTION_DOWN]) ? $this->options[self::ACTION_DOWN] : 0;

			if (isset($this->options[self::ACTION_FORCE])) {
				return $this->doDownForce($migrationId);
			}

			return $this->doDown($migrationId);
		}

		return $this->doHelp();
	}

	/**
	 * initialize db connection
	 */
	private function connect()
	{
		$cfg = $this->config['db'];

		try {
			$resource = mysqli_init();
			$resource->set_opt(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
			$resource->real_connect(
				$cfg['host'],
				$cfg['user'],
				$cfg['password'],
				$cfg['database'],
				$cfg['port']
			);
		}
		catch (Exception $e) {
			$msg = sprintf('Не удалось установить соединение с базой данных "%s": %s',
				$cfg['database'],
				$e->getMessage()
			);

			throw new Exception($msg, $e->getCode());
		}

		$this->db = $resource;
	}

	/**
	 * @param  array $options
	 *
	 * @return string
	 */
	private function parseAction(array $options)
	{
		if (array_key_exists(self::ACTION_STATUS, $options)) {
			return self::ACTION_STATUS;
		}
		if (array_key_exists(self::ACTION_GENERATE, $options)) {
			return self::ACTION_GENERATE;
		}
		if (array_key_exists(self::ACTION_INIT, $options)) {
			return self::ACTION_INIT;
		}
		if (array_key_exists(self::ACTION_UP, $options)) {
			return self::ACTION_UP;
		}
		if (array_key_exists(self::ACTION_DOWN, $options)) {
			return self::ACTION_DOWN;
		}

		return self::ACTION_HELP;
	}

	/**
	 * @return Migration[]
	 */
	private function getAllMigrations()
	{
		$fileList = array_diff(scandir($this->migration_path, SCANDIR_SORT_ASCENDING), ['..', '.']);

		$fileList = array_values(array_filter($fileList));

		$migrations = [];
		foreach ($fileList as $filename) {
			$id = ltrim(explode('_', $filename, 2)[0], '0');

			$description = preg_replace('~^\d+_(.+)\.sql$~usi', '\1', $filename);
			$description = trim(str_replace('_', ' ', $description));

			$migrations[$id] = new Migration($id, $this->migration_path . '/' . $filename, $description);
		}

		return $migrations;
	}

	/**
	 * @return Migration[]
	 */
	private function getAppliedMigrations()
	{
		$rows = $this->db->query('SELECT * FROM `__db_changelog` ORDER BY `id` ASC');

		$migrations = [];
		foreach ($rows as $row) {
			$migrations[$row['id']] = new Migration(
				$row['id'],
				$this->migration_path . '/' . $row['filename'],
				$row['description'],
				$row['applied_at'],
				true
			);
		}

		$rows->free_result();

		return $migrations;
	}

	/**
	 * @param  int $sort
	 *
	 * @return Migration[]
	 */
	private function getMigrations($sort=SORT_ASC)
	{
		$migrations = $this->getAppliedMigrations() + $this->getAllMigrations();

		if ($sort == SORT_ASC) {
			ksort($migrations);
		}
		else {
			krsort($migrations);
		}

		return $migrations;
	}

	/**
	 * @param  string $name
	 *
	 * @return string
	 */
	private function doGenerate($name)
	{
		$template = <<<'SQL'
SET NAMES UTF8, lc_time_names = ru_RU, sql_mode="STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE";
-- --------------------------------------------------------

-- //@UNDO

SQL;

		$id = count($this->getAllMigrations()) + 1;
		$filename = sprintf('%03d_%s.sql', $id, str_replace(' ', '_', $name));

		file_put_contents($this->migration_path . '/' . $filename, $template);

		return "Generated migration {$id} to file {$filename}\n";
	}

	/**
	 * @return string
	 */
	private function doInit()
	{
		$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `__db_changelog` (
  `id` int(10) unsigned NOT NULL COMMENT 'Код',
  `filename` varchar(128) NOT NULL COMMENT 'Имя файла',
  `description` varchar(128) NOT NULL COMMENT 'Описание',
  `applied_at` datetime NOT NULL COMMENT 'Дата применения',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Миграции';
SQL;

		$this->db->query($sql);

		return "Initial structure created\n";
	}

	/**
	 * @param  Migration $migration
	 *
	 * @return string
	 * @throws
	 */
	private function up(Migration $migration)
	{
		$filename = basename($migration->sqlFile);
		$this->db->autocommit(false);

		$sql = $migration->getSqlUp();
		if (! empty($sql)) {
			$this->db->multi_query($sql);
			while ($this->db->more_results()) {$this->db->next_result();}

			if ($this->db->errno != 0) {
				$error = $this->db->error;
				$this->db->rollBack();
				$this->db->autocommit(true);

				throw new Exception("UP Migration failure {$filename}\n" . $sql . "\n{$error}\n");
			}
		}

		$this->db->query(
			'INSERT INTO `__db_changelog` (`id`, `filename`, `description`, `applied_at`) VALUES (' .
			$migration->id . ', \'' .
			$filename . '\', \'' .
			$migration->description . '\', \'' .
			$migration->appliedAt . '\')'
		);

		$this->db->commit();
		$this->db->autocommit(true);

		$output = "up success {$filename}\n";
		if (isset($this->options[self::MODE_VERBOSE])) {
			$output .= str_repeat('=', 20) . "\n" . $migration->getSqlUp() . "\n";
		}

		return $output;
	}

	/**
	 * @param  Migration $migration
	 *
	 * @return string
	 * @throws
	 */
	private function down(Migration $migration)
	{
		$filename = basename($migration->sqlFile);
		$this->db->autocommit(false);

		$sql = $migration->getSqlDown();
		if (! empty($sql)) {
			$this->db->multi_query($sql);
			while ($this->db->more_results()) {$this->db->next_result();}

			if ($this->db->errno != 0) {
				$error = $this->db->error;
				$this->db->rollBack();
				$this->db->autocommit(true);

				throw new Exception("DOWN Migration failure {$filename}\n" . $sql . "\n{$error}\n");
			}
		}

		$this->db->query('DELETE FROM `__db_changelog` WHERE `id` = ' . $migration->id);

		$this->db->commit();
		$this->db->autocommit(true);

		$output = "down success {$filename}\n";
		if (isset($options[self::MODE_VERBOSE])) {
			$output .= str_repeat('=', 20) . "\n" . $migration->getSqlDown() . "\n";
		}

		return $output;
	}

	/**
	 * @param  string $id
	 *
	 * @return string
	 */
	private function doUpForce($id)
	{
		$migrations = $this->getMigrations();
		if (! isset($migrations[$id])) {
			return "Migration {$id} not found\n";
		}

		$migration = $migrations[$id];

		if ($migration->applied) {
			return "Migration {$id} already applied\n";
		}

		try {
			return $this->up($migration);
		}
		catch (Exception $e) {
			return $e->getMessage();
		}
	}

	/**
	 * @param  string $id
	 *
	 * @return string
	 */
	private function doDownForce($id)
	{
		$migrations = $this->getMigrations();
		if (! isset($migrations[$id])) {
			return "Migration {$id} not found\n";
		}

		$migration = $migrations[$id];

		if (! $migration->applied) {
			return "Migration {$id} are not applied\n";
		}

		try {
			return $this->down($migration);
		}
		catch (Exception $e) {
			return $e->getMessage();
		}
	}

	/**
	 * run all migration up to the provided id
	 *
	 * @param  string|null $id
	 *
	 * @return string
	 */
	private function doUp($id=null)
	{
		$applied = 0;
		foreach ($this->getMigrations() as $migration) {
			if (! $migration->applied) {
				try {
					echo $this->up($migration);
					$applied++;
				}
				catch (Exception $e) {
					echo $e->getMessage();

					return "Applied {$applied} migrations";
				}
			}

			if ($migration->id == $id) {
				break;
			}
		}

		return "Applied {$applied} migrations";
	}

	/**
	 * undoes all migration til the specified id (included)
	 *
	 * @param  string|null $id
	 *
	 * @return string
	 */
	private function doDown($id=null)
	{
		$result = '';
		foreach ($this->getMigrations(SORT_DESC) as $migration) {
			if ($migration->applied) {
				try {
					$result .= $this->down($migration);
				}
				catch (Exception $e) {
					$result .= $e->getMessage();

					return $result;
				}

				if (empty($id)) {
					break;
				}
			}

			if ($migration->id == $id) {
				break;
			}
		}

		return $result;
	}

	private function doStatus()
	{
		$status = '';
		$max_len = 11;
		foreach ($this->getMigrations() as $migration) {
			$applied_at = str_pad($migration->applied ? $migration->appliedAt : 'pending...', 21, ' ');
			$status .= sprintf("%03d  %s%s\n", $migration->id, $applied_at, $migration->description);
			$max_len = max($max_len, strlen($migration->description));
		}

		return "ID   Applied At           Description\n" . str_repeat('=', 27 + $max_len) . "\n" . $status;
	}

	/**
	 * #return string
	 */
	private function doHelp()
	{
		return <<<'HELP'
Usage: ./migrate command [parameters]

Commands:
  --generate <description>  Creates a new migration with the provided description.
  --up                      Run unapplied migrations, ALL by default.
  --down                    Undoes migrations applied to the database. ONE by default.
  --force                   Run or undoes only specified migration (not recommended).
  --status                  Show migrations status (applied, unapplied ect...).
  --verbose                 Verbose mode

Examples:
./migrate --generate=<description>
./migrate [--up | --down] [--force]
./migrate --status
HELP;
	}
}

$migrate = new Migrate();
try {
	echo "\n" . $migrate->run() . "\n";
}
catch (Exception $e) {
	echo "\nERROR [" . $e->getCode() . '] ' . $e->getMessage() . "\n";
}
