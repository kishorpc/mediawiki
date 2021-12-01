<?php

/**
 * Convert a JSON abstract schema change to a schema change file in the given DBMS type
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

use Doctrine\SqlFormatter\NullHighlighter;
use Doctrine\SqlFormatter\SqlFormatter;
use Wikimedia\Rdbms\DoctrineSchemaBuilderFactory;

require_once __DIR__ . '/Maintenance.php';

/**
 * Maintenance script to generate schema from abstract json files.
 *
 * @ingroup Maintenance
 */
class GenerateSchemaChangeSql extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Build SQL files from abstract JSON files' );

		$this->addOption(
			'json',
			'Path to the json file.',
			true,
			true
		);
		$this->addOption(
			'sql',
			'Path to output.',
			true,
			true
		);
		$this->addOption(
			'type',
			'Can be either \'mysql\', \'sqlite\', or \'postgres\'. Default: mysql',
			false,
			true
		);
	}

	public function execute() {
		global $IP;
		$platform = $this->getOption( 'type', 'mysql' );
		$jsonPath = $this->getOption( 'json' );
		$installPath = $IP;
		// For windows
		if ( DIRECTORY_SEPARATOR === '\\' ) {
			$installPath = strtr( $installPath, '\\', '/' );
			$jsonPath = strtr( $jsonPath, '\\', '/' );
		}
		$relativeJsonPath = str_replace( "$installPath/", '', $jsonPath );
		$sqlPath = $this->getOption( 'sql' );
		// Allow to specify a folder and build the name from the json filename
		if ( is_dir( $sqlPath ) ) {
			$sqlPath .= '/' . pathinfo( $relativeJsonPath, PATHINFO_FILENAME ) . '.sql';
		}
		$abstractSchemaChange = json_decode( file_get_contents( $jsonPath ), true );

		if ( $abstractSchemaChange === null ) {
			$this->fatalError( "'$jsonPath' seems to be invalid json. Check the syntax and try again!" );
		}

		$schemaChangeBuilder = ( new DoctrineSchemaBuilderFactory() )->getSchemaChangeBuilder( $platform );

		$schemaChangeSqls = $schemaChangeBuilder->getSchemaChangeSql( $abstractSchemaChange );

		$sql = "-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.\n" .
			"-- Source: $relativeJsonPath\n" .
			"-- Do not modify this file directly.\n" .
			"-- See https://www.mediawiki.org/wiki/Manual:Schema_changes\n";

		if ( $schemaChangeSqls !== [] ) {
			// Temporary
			$sql .= implode( ";\n\n", $schemaChangeSqls ) . ';';
			$sql = ( new SqlFormatter( new NullHighlighter() ) )->format( $sql );
		} else {
			$this->error( 'No schema changes detected!' );
		}

		// Postgres hacks
		if ( $platform === 'postgres' ) {
			// Remove table prefixes from Postgres schema, people should not set it
			// but better safe than sorry.
			$sql = str_replace( "\n/*_*/\n", ' ', $sql );

			// MySQL goes with varbinary for collation reasons, but postgres can't
			// properly understand BYTEA type and works just fine with TEXT type
			// FIXME: This should be fixed at some point (T257755)
			$sql = str_replace( "BYTEA", 'TEXT', $sql );
		}

		// Until the linting issue is resolved
		// https://github.com/doctrine/sql-formatter/issues/53
		$sql = str_replace( "\n/*_*/\n", " /*_*/", $sql );
		$sql = str_replace( "; ", ";\n", $sql );
		$sql = preg_replace( "/\n+? +?/", ' ', $sql );
		$sql = str_replace( "/*_*/  ", "/*_*/", $sql );

		// Sqlite hacks
		if ( $platform === 'sqlite' ) {
			// Doctrine prepends __temp__ to the table name and we set the table with the schema prefix causing invalid
			// sqlite.
			$sql = preg_replace( '/__temp__\s*\/\*_\*\//', '/*_*/__temp__', $sql );
		}

		// Give a hint, if nothing changed
		if ( is_readable( $sqlPath ) ) {
			$oldSql = file_get_contents( $sqlPath );
			if ( $oldSql === $sql ) {
				$this->output( "Schema change is unchanged.\n" );
			}
		}

		file_put_contents( $sqlPath, $sql );
		$this->output( 'Schema change generated and written to ' . $sqlPath . "\n" );
	}

}

$maintClass = GenerateSchemaChangeSql::class;
require_once RUN_MAINTENANCE_IF_MAIN;
