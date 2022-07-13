<?php
/**
 * WSStatsHooks
 *
 *
 * @license https://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 *
 */

namespace WSStats;

use Parser, Title, ALTree, OutputPage, Skin, WSStats\export\WSStatsExport, MediaWiki\MediaWikiServices;
use RequestContext;

if ( ! defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

ERROR_REPORTING( E_ALL );
ini_set( 'display_errors', 1 );

/**
 * Class WSStatsHooks
 */
class WSStatsHooks {

	const DBTABLE = 'WSPS';


	/**
	 * WSStatsHooks constructor.
	 */
	public function __construct() {
		date_default_timezone_set( 'UTC' );
	}

	/**
	 * @return bool
	 */
	public static function isAnon() {
		global $wgUser;

		return $wgUser->isAnon();
	}

	/**
	 * @param string $name
	 *
	 * @return mixed
	 */
	public static function getConfigSetting( string $name ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if ( $config->has( 'WSStats' ) ) {
			$WSStatsConfig = $config->get( 'WSStats' );
			if ( isset ( $WSStatsConfig[$name] ) ) {
				return $WSStatsConfig[$name];
			}
		}
		return false;
	}

	/**
	 * @param int $id
	 *
	 * @return mixed
	 */
	public static function getPageTitleFromID( $id ) {
		$title = Title::newFromID( $id );
		if ( is_null( $title ) ) {
			return null;
		}
		return $title->getFullText();
	}

	public static function validateDate( $date, $format = 'Y-m-d H:i:s' ) {
		if ( strpos(
			$date,
			' '
		) ) {
			// we have also a time added
			$xploded      = explode(
				' ',
				$date
			);
			$timeExploded = explode(
				":",
				$xploded[1]
			);
			$timeCount    = count( $timeExploded );
			switch ( $timeCount ) {
				case 0:
					$date   = $xploded[0];
					$format = 'Y-m-d';
					break;
				case 1:
					$format = 'Y-m-d H';
					break;
				case 2:
					$format = 'Y-m-d H:i';
					break;
				case 3:
					$format = 'Y-m-d H:i:s';
					break;
				default:
					$format = 'Y-m-d';
			}
		}
		$d = \DateTime::createFromFormat(
			$format,
			$date
		);

		return $d && $d->format( $format ) == $date;
	}

	/**
	 * Implements AdminLinks hook from Extension:Admin_Links.
	 *
	 * @param ALTree &$adminLinksTree
	 *
	 * @return bool
	 */
	public static function addToAdminLinks( \ALTree &$adminLinksTree ) {
		global $wgServer;
		$wsSection = $adminLinksTree->getSection( 'WikiBase Solutions' );
		if ( is_null( $wsSection ) ) {
			$section = new \ALSection( 'WikiBase Solutions' );
			$adminLinksTree->addSection(
				$section,
				wfMessage( 'adminlinks_general' )->text()
			);
			$wsSection     = $adminLinksTree->getSection( 'WikiBase Solutions' );
			$extensionsRow = new \ALRow( 'extensions' );
			$wsSection->addRow( $extensionsRow );
		}

		$extensionsRow = $wsSection->getRow( 'extensions' );

		if ( is_null( $extensionsRow ) ) {
			$extensionsRow = new \ALRow( 'extensions' );
			$wsSection->addRow( $extensionsRow );
		}
		$extensionsRow->addItem(
			\ALItem::newFromExternalLink(
				$wgServer . '/index.php/Special:WSStats',
				'WS Statistics'
			)
		);

		return true;
	}

	/**
	 * When running maintenance update with will add the database tables
	 *
	 * @param [type] $updater [description]
	 */
	public static function addTables( $updater ) {
		$dbt = $updater->getDB()->getType();
		// If using SQLite, just use the MySQL/MariaDB schema, it's compatible
		// anyway. Only PGSQL and some more exotic variants need a totally
		// different schema.
		if ( $dbt === 'sqlite' ) {
			$dbt = 'sql';
		}
		$tables = __DIR__ . "/../sql/WSStats.$dbt";

		if ( file_exists( $tables ) ) {
			$updater->addExtensionUpdate( array(
				'addTable',
				self::DBTABLE,
				$tables,
				true
			) );
		} else {
			throw new \MWException( "WSStats does not support $dbt." );
		}

		return true;
	}


	/**
	 * @param int $id
	 * @param array|false $dates
	 * @param string|false $type
	 * @param bool $unique
	 *
	 * @return int|mixed
	 */
	public static function getViewsPerPage( int $id, $dates = false, $type = false, bool $unique = false ) {
		global $wgDBprefix;
		switch ( $type ) {
			case "only anonymous":
				$dbType = "user_id = 0 ";
				break;
			case "only user":
				$dbType = "user_id <> 0 ";
				break;
			default:
				$dbType = false;
		}
		$cnt = '*';
		if ( $unique ) {
			$cnt = 'DISTINCT(user_id)';
		}

		$lb               = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbr              = $lb->getConnectionRef( DB_REPLICA );
		$dbResult         = array();
		$selectWhat       = [
			'page_id',
			"count" => 'COUNT(' . $cnt . ')'
		];
		$selectOptions    = [
			'GROUP BY' => 'page_id',
			'ORDER BY' => 'count DESC',
			'LIMIT'    => 1
		];
		$selectConditions = array();

		if ( $dates === false ) {
			// Set Conditions
			if ( ! $dbType ) {
				$selectConditions = [
					"page_id = " . $id
				];
			} else {
				$selectConditions = [
					"page_id = " . $id,
					$dbType
				];
			}
			//$sql = 'SELECT page_id, COUNT(' . $cnt . ') AS count FROM ' . $wgDBprefix . 'WSPS WHERE page_id=\'' . $id . '\' ' . $dbType . 'GROUP BY page_id ORDER BY count DESC LIMIT 1';
		} else {
			if ( $dates['e'] === false ) {
				// Set Conditions
				if ( ! $dbType ) {
					$selectConditions = [
						"page_id = " . $id,
						'added BETWEEN \'' . $dates["b"] . '\' AND NOW()'
					];
				} else {
					$selectConditions = [
						"page_id = " . $id,
						$dbType,
						'added BETWEEN \'' . $dates["b"] . '\' AND NOW()'
					];
				}
				//$sql = 'SELECT page_id, COUNT(' . $cnt . ') AS count FROM ' . $wgDBprefix . 'WSPS WHERE page_id=\'' . $id . '\' ' . $dbType . 'AND added BETWEEN \'' . $dates["b"] . '\' AND NOW()';
			} else {
				// Set Conditions
				if ( ! $dbType ) {
					$selectConditions = [
						"page_id = " . $id,
						'added >= \'' . $dates["b"] . '\' AND added <= \'' . $dates['e'] . '\''
					];
				} else {
					$selectConditions = [
						"page_id = " . $id,
						$dbType,
						'added >= \'' . $dates["b"] . '\' AND added <= \'' . $dates['e'] . '\''
					];
				}
				//$sql      = 'SELECT page_id, COUNT(' . $cnt . ') AS count FROM ' . $wgDBprefix . 'WSPS WHERE page_id=\'' . $id . '\' ' . $dbType . 'AND added >= \'' . $dates["b"] . '\' AND added <= \'' . $dates['e'] . '\' GROUP BY page_id ORDER BY COUNT DESC LIMIT 1';
			}
		}

		$res      = $dbr->select(
			$wgDBprefix . self::DBTABLE,
			$selectWhat,
			$selectConditions,
			__METHOD__,
			$selectOptions
		);
		$dbResult = $res->fetchRow();
		if ( ! isset( $dbResult['count'] ) || empty( $dbResult['count'] ) ) {
			return 0;
		} else {
			return $dbResult['count'];
		}
	}

	/**
	 * @param array|false $dates
	 * @param string $render
	 * @param bool $unique
	 * @param string $variable
	 * @param int $limit
	 * @param int $limit
	 *
	 * @return string
	 */
	public static function getMostViewedPages(
		$dates = false,
		string $render = "table",
		bool $unique = false,
		string $variable = "",
		int $limit = 10,
		int $pId = 0
	): string {
		global $wgDBprefix;

		$cnt = '*';
		if ( $unique ) {
			$cnt = 'DISTINCT(user_id)';
		}


		$lb       = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbr      = $lb->getConnectionRef( DB_REPLICA );
		$dbResult = array();

		if ( $pId === 0 ) {
			$selectWhat    = [
				'page_id',
				"count" => 'COUNT(' . $cnt . ')'
			];
			$selectOptions = [
				'GROUP BY' => 'page_id',
				'ORDER BY' => 'count DESC',
				'LIMIT'    => $limit
			];
		} else {
			$selectWhat    = [
				'page_id',
				'Date'  => 'DATE(added)',
				"count" => 'COUNT(' . $cnt . ')'
			];
			$selectOptions = [
				'GROUP BY' => 'Date',
				'ORDER BY' => 'Date ASC',
				'LIMIT'    => $limit
			];
		}

		$selectConditions = array();

		if ( $pId !== 0 ) {
			$selectConditions[] = "page_id = '" . $pId . "'";
		}

		if ( $dates === false ) {

			//$sql = 'SELECT page_id, COUNT(' . $cnt . ') AS count FROM ' . $wgDBprefix . 'WSPS GROUP BY page_id ORDER BY count DESC LIMIT ' . $limit;
		} else {
			if ( $dates['e'] === false ) {
				$selectConditions[] = 'added BETWEEN \'' . $dates["b"] . '\' AND AND NOW()';
				//$sql = 'SELECT page_id, COUNT(' . $cnt . ') AS count FROM ' . $wgDBprefix . 'WSPS WHERE added BETWEEN \'' . $dates["b"] . '\' AND NOW() GROUP BY page_id ORDER BY count DESC LIMIT ' . $limit;
			} else {
				$selectConditions[] = 'added >= \'' . $dates["b"] . '\' AND added <= \'' . $dates['e'] . '\'';
				//$sql = 'SELECT page_id, COUNT(' . $cnt . ') AS count FROM ' . $wgDBprefix . 'WSPS WHERE added >= \'' . $dates["b"] . '\' AND added <= \'' . $dates['e'] . '\' GROUP BY page_id ORDER BY COUNT DESC LIMIT ' . $limit;
			}
		}

		$res = $dbr->select(
			$wgDBprefix . self::DBTABLE,
			$selectWhat,
			$selectConditions,
			__METHOD__,
			$selectOptions
		);

		$data = "";
		if ( $res->numRows() > 0 ) {
			$renderMethod = new WSStatsExport();
			switch ( $render ) {
				case "table":
					$data = $renderMethod->renderTable( $res, $pId );
					break;
				case "csv":
					$data = $renderMethod->renderCSV( $res, $pId );
					break;
				case "wsarrays":
					$data = $renderMethod->renderWSArrays(
						$res,
						$variable,
						$pId
					);
					break;
				default:
					$data = "";
			}
		}

		return $data;
	}

	/**
	 * @param array $options
	 * @param string $k
	 * @param bool $checkEmpty
	 *
	 * @return bool|mixed
	 */
	public static function getOptionSetting( array $options, string $k, bool $checkEmpty = true ) {
		if ( $checkEmpty ) {
			if ( isset( $options[ $k ] ) && $options[ $k ] != '' ) {
				return $options[ $k ];
			} else {
				return false;
			}
		} else {
			if ( isset( $options[ $k ] ) ) {
				return true;
			} else {
				return false;
			}
		}
	}

	/**
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser &$parser ) {
		$parser->setFunctionHook(
			'wsstats',
			'WSStats\WSStatsHooks::wsstats'
		);
	}

	/**
	 * @return bool
	 */
	private static function countAllUserGroups(): bool {
		$user = RequestContext::getMain()->getUser();
		$uGroups = MediaWikiServices::getInstance()->getUserGroupManager()->getUserGroups( $user );
		$skipUserGroups = self::getConfigSetting( 'skip_user_groups' );
		if ( $skipUserGroups !== false ) {
			if ( is_array( $skipUserGroups) ) {
				$groups = $skipUserGroups;
				foreach ( $groups as $group ) {
					if ( in_array(
						$group,
						$uGroups
					) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * @param string|bool $ref
	 *
	 * @return bool
	 */
	private static function ignoreInUrl( $ref ): bool {
		$ignoreInUrl = self::getConfigSetting( 'ignore_in_url' );
		if ( $ignoreInUrl !== false && is_array( $ignoreInUrl ) && $ref !== false ) {
			foreach ( $ignoreInUrl as $single ) {
				if ( strpos(
					     $ref,
					     $single
				     ) !== false ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @return bool
	 */
	private static function removeDeletePages(): bool {
		$removeDeletePagesFromStats = self::getConfigSetting( 'remove_deleted_pages_from_stats' );
		if ( $removeDeletePagesFromStats === true ) {
			return true;
		}

		return false;
	}

	/**
	 * @return bool
	 */
	private static function skipAnonymous(): bool {
		$user = RequestContext::getMain()->getUser();
		$skipAnonymous = self::getConfigSetting( 'skip_anonymous' );
		if ( $skipAnonymous === true ) {
			if ( $user->isAnon() ) {
				return true;
			}
		}

		return false;
	}


	/**
	 * @param outputPage $output
	 * @param Skin $skin
	 *
	 * @return bool
	 */
	public static function onBeforePageDisplay( outputPage &$output, Skin &$skin ): bool {
		$user = RequestContext::getMain()->getUser();

		if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$ref = $_SERVER['HTTP_REFERER'];
		} else {
			$ref = false;
		}

		if ( self::countAllUserGroups() ) {
			return true;
		}
		if ( self::ignoreInUrl( $ref ) ) {
			return true;
		}
		if ( self::skipAnonymous() ) {
			return true;
		}

		if ( $user->isAnon() ) {
			$data['user_id'] = 0;
		} else {
			$data['user_id'] = $user->getID();
		}
		$title = $output->getTitle();

		if ( $title === null ) {
			return true;
		}
		$data['page_id'] = $title->getArticleID();

		if ( $data['page_id'] != 0 ) {
			WSStatsHooks::insertRecord(
				self::DBTABLE,
				$data
			);
		}

		return true;
	}

	/**
	 * @param Parser $parser
	 *
	 * @return int|mixed|string
	 */
	public static function wsstats( Parser &$parser ) {
		$options = WSStatsHooks::extractOptions(
			array_slice(
				func_get_args(),
				1
			)
		);
		$unique  = WSStatsHooks::getOptionSetting(
			$options,
			'unique',
			false
		);
		$limit   = WSStatsHooks::getOptionSetting(
			$options,
			'limit'
		);
		$limit   = intval( $limit );
		if ( $limit === 0 ) {
			$limit = 10;
		}
		$dates      = array();
		$dates['b'] = WSStatsHooks::getOptionSetting(
			$options,
			'start date'
		);
		$dates['e'] = WSStatsHooks::getOptionSetting(
			$options,
			'end date'
		);
		if ( $dates['b'] !== false && self::validateDate( $dates['b'] ) === false ) {
			$dates['b'] = false;
		}
		if ( $dates['e'] !== false && self::validateDate( $dates['e'] ) === false ) {
			$dates['e'] = false;
		}
		$pid = WSStatsHooks::getOptionSetting(
			$options,
			'id'
		);
		$pid = intval( $pid );
		if ( isset( $options['stats'] ) ) {
			$wsArrayName = "";
			$format      = WSStatsHooks::getOptionSetting(
				$options,
				'format'
			);

			if ( $format === false ) {
				$format = 'table';
			}
			if ( strtolower( $format ) === 'wsarrays' ) {
				$wsArrayName = WSStatsHooks::getOptionSetting(
					$options,
					'name'
				);
				if ( false === $wsArrayName ) {
					$format = 'table';
				}
			}
			if ( $dates['e'] === false && $dates['b'] !== false ) {
				$dates['e'] = false;
			}
			if ( $dates['b'] === false && $dates['e'] !== false ) {
				$dates = false;
			}
			if ( $dates['b'] === false && $dates['e'] === false ) {
				$dates = false;
			}
			$data = WSStatsHooks::getMostViewedPages(
				$dates,
				$format,
				$unique,
				$wsArrayName,
				$limit,
				$pid
			);

			return $data;
		}
		if ( $pid !== 0 ) {
			$type = WSStatsHooks::getOptionSetting(
				$options,
				'type'
			);

			if ( $dates['e'] === false && $dates['b'] !== false ) {
				$dates['e'] = false;
			}
			if ( $dates['b'] === false && $dates['e'] !== false ) {
				$dates = false;
			}
			if ( $dates['b'] === false && $dates['e'] === false ) {
				$dates = false;
			}
			$data = WSStatsHooks::getViewsPerPage(
				$pid,
				$dates,
				$type,
				$unique
			);
			if ( $data !== null ) {
				return $data;
			} else {
				return "";
			}
		}

		return "ok, move along. Nothing to see here..";
	}

	private static function deleteRecord( $table, $pId ): bool {
		$dbw               = wfGetDB( DB_MASTER );
		$dbw->IngoreErrors = true;
		try {
			$res = $dbw->delete(
				$table,
				"page_id = " . $pId,
				__METHOD__
			);
		} catch ( Exception $e ) {
			echo $e;

			return false;
		}

		if ( $res ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * @param string $table
	 * @param array $vals
	 *
	 * @return bool
	 */
	public static function insertRecord( string $table, array $vals ): bool {
		$dbw               = wfGetDB( DB_MASTER );
		$dbw->IngoreErrors = true;
		try {
			$res = $dbw->insert(
				$table,
				$vals,
				__METHOD__
			);
		} catch ( Exception $e ) {
			echo $e;

			return false;
		}
		var_dump( $table );
		var_dump( $vals );
		if ( $res ) {
			return true;
		} else {
			return false;
		}
	}


	/**
	 * Converts an array of values in form [0] => "name=value" into a real
	 * associative array in form [name] => value. If no = is provided,
	 * true is assumed like this: [name] => true
	 *
	 * @param array string $options
	 *
	 * @return array $results
	 */
	public static function extractOptions( array $options ) {
		$results = array();
		foreach ( $options as $option ) {
			$pair = explode(
				'=',
				$option,
				2
			);
			if ( $pair[0] !== '//' ) {
				if ( count( $pair ) === 2 ) {
					$name = strtolower( trim( $pair[0] ) );
					if ( $name == 'template' ) {
						$value = trim( $pair[1] );
					} else {
						$value = strtolower( trim( $pair[1] ) );
					}

					$results[ $name ] = $value;
				}
				if ( count( $pair ) === 1 ) {
					$name             = trim( $pair[0] );
					$results[ $name ] = true;
				}
			}
		}

		return $results;
	}

}
