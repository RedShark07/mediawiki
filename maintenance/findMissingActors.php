<?php
/**
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

use MediaWiki\MediaWikiServices;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;
use MediaWiki\User\UserRigorOptions;
use Wikimedia\Rdbms\LBFactory;
use Wikimedia\Rdbms\LoadBalancer;

require_once __DIR__ . '/Maintenance.php';

/**
 * Maintenance script for finding and replacing invalid actor IDs, see T261325 and T307738.
 *
 * @ingroup Maintenance
 */
class FindMissingActors extends Maintenance {

	/**
	 * @var UserFactory|null
	 */
	private $userFactory;

	/**
	 * @var UserNameUtils|null
	 */
	private $userNameUtils;

	/**
	 * @var LoadBalancer|null
	 */
	private $loadBalancer;

	/**
	 * @var LBFactory
	 */
	private $lbFactory;

	/**
	 * @var ActorNormalization
	 */
	private $actorNormalization;

	/** @var array|null */
	private $tables;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Find and fix invalid actor IDs.' );
		$this->addOption( 'field', 'The name of a database field to process',
			true, true );
		$this->addOption( 'type', 'Which type of invalid actors to find or fix, '
			. 'missing or broken (with empty actor_name which can\'t be associated '
			. 'with an existing user).',
			false, true );
		$this->addOption( 'skip', 'A comma-separated list of actor IDs to skip.',
			false, true );
		$this->addOption( 'overwrite-with', 'Replace invalid actors with this user. '
			. 'Typically, this would be "Unknown user", but it could be any reserved '
			. 'system user (per $wgReservedUsernames) or locally registered user. '
			. 'If not given, invalid actors will only be listed, not fixed. '
			. 'You will be prompted for confirmation before data is written. ',
			false, true );

		$this->setBatchSize( 1000 );
	}

	public function initializeServices(
		?UserFactory $userFactory = null,
		?UserNameUtils $userNameUtils = null,
		?LoadBalancer $loadBalancer = null,
		?LBFactory $lbFactory = null,
		?ActorNormalization $actorNormalization = null
	) {
		$services = MediaWikiServices::getInstance();

		$this->userFactory = $userFactory ?? $this->userFactory ?? $services->getUserFactory();
		$this->userNameUtils = $userNameUtils ?? $this->userNameUtils ?? $services->getUserNameUtils();
		$this->loadBalancer = $loadBalancer ?? $this->loadBalancer ?? $services->getDBLoadBalancer();
		$this->lbFactory = $lbFactory ?? $this->lbFactory ?? $services->getDBLoadBalancerFactory();
		$this->actorNormalization = $actorNormalization ?? $this->actorNormalization ??
			$services->getActorNormalization();
	}

	/**
	 * @return array
	 */
	private function getTables() {
		global $wgActorTableSchemaMigrationStage;

		if ( !$this->tables ) {
			$tables = [
				'ar_actor' => [ 'archive', 'ar_actor', 'ar_id' ],
				'ipb_by_actor' => [ 'ipblocks', 'ipb_by_actor', 'ipb_id' ], // no index on ipb_by_actor!
				'img_actor' => [ 'image', 'img_actor', 'img_name' ],
				'oi_actor' => [ 'oldimage', 'oi_actor', 'oi_archive_name' ], // no index on oi_archive_name!
				'fa_actor' => [ 'filearchive', 'fa_actor', 'fa_id' ],
				'rc_actor' => [ 'recentchanges', 'rc_actor', 'rc_id' ],
				'log_actor' => [ 'logging', 'log_actor', 'log_id' ],
			];

			if ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_WRITE_TEMP ) {
				$tables['revactor_actor'] = [ 'revision_actor_temp', 'revactor_actor', 'revactor_rev' ];
			}
			if ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_WRITE_NEW ) {
				$tables['rev_actor'] = [ 'revision', 'rev_actor', 'rev_id' ];
			}
			$this->tables = $tables;
		}
		return $this->tables;
	}

	/**
	 * @param string $field
	 * @return array|null
	 */
	private function getTableInfo( $field ) {
		$tables = $this->getTables();
		return $tables[$field] ?? null;
	}

	/**
	 * Returns the actor ID of the user specified with the --overwrite-with option,
	 * or null if --overwrite-with is not set.
	 *
	 * Existing users and reserved system users are supported.
	 * If the user does not have an actor ID yet, one will be assigned.
	 *
	 * @return int|null
	 */
	private function getNewActorId() {
		$name = $this->getOption( 'overwrite-with' );

		if ( $name === null ) {
			return null;
		}

		$user = $this->userFactory->newFromName( $name );

		if ( !$user ) {
			$this->fatalError( "Not a valid user name: '$name'" );
		}

		$name = $this->userNameUtils->getCanonical( $name, UserRigorOptions::RIGOR_NONE );

		if ( $user->isRegistered() ) {
			$this->output( "Using existing user: '$user'\n" );
		} elseif ( !$this->userNameUtils->isValid( $name ) ) {
			$this->fatalError( "Not a valid user name: '$name'" );
		} elseif ( !$this->userNameUtils->isUsable( $name ) ) {
			$this->output( "Using system user: '$name'\n" );
		} else {
			$this->fatalError( "Unknown user: '$name'" );
		}

		$dbw = $this->loadBalancer->getConnectionRef( DB_PRIMARY );
		$actorId = $this->actorNormalization->acquireActorId( $user, $dbw );

		if ( !$actorId ) {
			$this->fatalError( "Failed to acquire an actor ID for user '$user'" );
		}

		$this->output( "Replacement actor ID is $actorId.\n" );
		return $actorId;
	}

	public function execute() {
		$this->initializeServices();

		$field = $this->getOption( 'field' );
		if ( !$this->getTableInfo( $field ) ) {
			$this->fatalError( "Unknown field: $field.\n" );
		}

		$type = $this->getOption( 'type', 'missing' );
		if ( $type !== 'missing' && $type !== 'broken' ) {
			$this->fatalError( "Unknown type: $type.\n" );
		}

		$skip = $this->parseIntList( $this->getOption( 'skip', '' ) );
		$overwrite = $this->getNewActorId();

		$bad = $this->findBadActors( $field, $type, $skip );

		if ( $bad && $overwrite ) {
			$this->output( "\n" );
			$this->output( "Do you want to OVERWRITE the listed actor IDs?\n" );
			$this->output( "Information about the invalid IDs will be lost!\n" );
			$this->output( "\n" );
			$confirm = self::readconsole( 'Type "yes" to continue: ' );

			if ( $confirm === 'yes' ) {
				$this->overwriteActorIDs( $field, array_keys( $bad ), $overwrite );
			} else {
				$this->fatalError( 'Aborted.' );
			}
		}

		$this->output( "Done.\n" );
	}

	/**
	 * Find rows that have bad actor IDs.
	 *
	 * @param string $field the database field in which to detect bad actor IDs.
	 * @param string $type type of bad actors, missing or broken.
	 * @param int[] $skip bad actor IDs not to replace.
	 *
	 * @return array a list of row IDs, identifying rows in which the actor ID needs to be replaced.
	 */
	private function findBadActors( $field, $type, $skip ) {
		[ $table, $actorField, $idField ] = $this->getTableInfo( $field );
		$this->output( "Finding invalid actor IDs in $table.$actorField...\n" );

		$dbr = $this->loadBalancer->getConnectionRef(
			DB_REPLICA,
			[ 'maintenance', 'vslow', 'slow' ]
		);

		/*
		We are building an SQL query like this one here, performing a left join
		to detect rows in $table that lack a matching row in the actor table.

		In this example, $field is 'log_actor', so $table is 'logging',
		$actorField is 'log_actor', and $idField is 'log_id'.
		Further, $skip is [ 1, 2, 3, 4 ] and the batch size is 1000.

		SELECT log_id
		FROM logging
		LEFT JOIN actor ON log_actor = actor_id
		WHERE actor_id IS NULL
		AND log_actor NOT IN (1, 2, 3, 4)
		LIMIT 1000;
		*/

		$conds = $type == 'missing'
			? [ 'actor_id' => null ]
			: [ 'actor_name' => '' ];

		if ( $skip ) {
			$conds[] = $actorField . ' NOT IN ( ' . $dbr->makeList( $skip ) . ' ) ';
		}

		$queryBuilder = $dbr->newSelectQueryBuilder();
		$queryBuilder->table( $table )
			->fields( [ $actorField, $idField ] )
			->conds( $conds )
			->leftJoin( 'actor', null, [ "$actorField = actor_id" ] )
			->limit( $this->getBatchSize() )
			->caller( __METHOD__ );

		$res = $queryBuilder->fetchResultSet();
		$count = $res->numRows();

		$bad = [];

		if ( $count ) {
			$this->output( "\t\tID\tACTOR\n" );
		}

		foreach ( $res as $row ) {
			$id = $row->$idField;
			$actor = (int)( $row->$actorField );

			$bad[$id] = $actor;
			$this->output( "\t\t$id\t$actor\n" );
		}

		$this->output( "\tFound $count invalid actor IDs.\n" );

		if ( $count >= $this->getBatchSize() ) {
			$this->output( "\tBatch size reached, run again after fixing the current batch.\n" );
		}

		return $bad;
	}

	/**
	 * Overwrite the actor ID in a given set of rows.
	 *
	 * @param string $field the database field in which to replace IDs.
	 * @param array $ids The row IDs of the rows in which the actor ID should be replaced
	 * @param int $overwrite The actor ID to write to the rows identified by $ids.
	 *
	 * @return int
	 */
	private function overwriteActorIDs( $field, array $ids, int $overwrite ) {
		[ $table, $actorField, $idField ] = $this->getTableInfo( $field );

		$count = count( $ids );
		$this->output( "OVERWRITING $count actor IDs in $table.$actorField with $overwrite...\n" );

		$dbw = $this->loadBalancer->getConnectionRef( DB_PRIMARY );

		$dbw->update( $table, [ $actorField => $overwrite ], [ $idField => $ids ], __METHOD__ );

		$count = $dbw->affectedRows();

		$this->lbFactory->waitForReplication();
		$this->output( "\tUpdated $count rows.\n" );

		return $count;
	}

}

$maintClass = FindMissingActors::class;
require_once RUN_MAINTENANCE_IF_MAIN;
