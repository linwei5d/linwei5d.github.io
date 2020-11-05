<?php
/*
** Copyright 2010-2015, Pye Brook Company, Inc.
**
**
** This software is provided under the GNU General Public License, version
** 2 (GPLv2), that covers its  copying, distribution and modification. The 
** GPLv2 license specifically states that it only covers only copying,
** distribution and modification activities. The GPLv2 further states that 
** all other activities are outside of the scope of the GPLv2.
**
** All activities outside the scope of the GPLv2 are covered by the Pye Brook
** Company, Inc. License. Any right not explicitly granted by the GPLv2, and 
** not explicitly granted by the Pye Brook Company, Inc. License are reserved
** by the Pye Brook Company, Inc.
**
** This software is copyrighted and the property of Pye Brook Company, Inc.
**
** Contact Pye Brook Company, Inc. at info@pyebrook.com for more information.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY 
** WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR 
** A PARTICULAR PURPOSE. 
**
*/

/*******************************************************
 * THIS CODE ADAPTED FROM
 * Memcached
 * Memcached backend for the WP Object Cache.
 * Version: 2.0.2
 * see http://wordpress.org/extend/plugins/memcached/
 * Authors Ryan Boren, Denis de Bernardy, Matt Martz
 */


function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	return $wp_object_cache->add( $key, $data, $group, $expire );
}

function wp_cache_incr( $key, $n = 1, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->incr( $key, $n, $group );
}

function wp_cache_decr( $key, $n = 1, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->decr( $key, $n, $group );
}

function wp_cache_close() {
	global $wp_object_cache;

	return $wp_object_cache->close();
}

function wp_cache_delete( $key, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->delete( $key, $group );
}

function wp_cache_flush() {
	global $wp_object_cache;

	return $wp_object_cache->flush();
}

function wp_cache_get( $key, $group = '', $force = false ) {
	global $wp_object_cache;

	return $wp_object_cache->get( $key, $group, $force );
}

function wp_cache_init() {
	global $wp_object_cache;
	$wp_object_cache = new WP_Object_Cache();
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	return $wp_object_cache->replace( $key, $data, $group, $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	if ( defined( 'WP_INSTALLING' ) == false ) {
		return $wp_object_cache->set( $key, $data, $group, $expire );
	} else {
		return $wp_object_cache->delete( $key, $group );
	}
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}

function wordpress_memcached_get_stats() {
	global $wp_object_cache;
	return $wp_object_cache->stats();
}


class WP_Object_Cache {
	private $global_groups = array();

	private $no_mc_groups = array();

	private $cache = array();
	private $mc = array();
	private $stats = array( 'add' => 0, 'delete' => 0, 'get' => 0, 'get_multi' => 0, );
	private $group_ops = array();

	private $cache_enabled = true;
	private $default_expiration = 0;

	function add( $id, $data, $group = 'default', $expire = 0 ) {
		$key = $this->key( $id, $group );

		if ( is_object( $data ) ) {
			$data = clone $data;
		}

		if ( in_array( $group, $this->no_mc_groups ) ) {
			$this->cache[ $key ] = $data;

			return true;
		} elseif ( isset( $this->cache[ $key ] ) && $this->cache[ $key ] !== false ) {
			return false;
		}

		$mc     =& $this->get_mc( $group );
		$expire = ( $expire == 0 ) ? $this->default_expiration : $expire;
		$result = $mc->add( $key, $data, false, $expire );

		if ( false !== $result ) {
			if ( isset( $this->stats['add'] ) ) {
				++ $this->stats['add'];
			}

			$this->group_ops[ $group ][] = "add $id";
			$this->cache[ $key ]         = $data;
		}

		return $result;
	}

	function add_global_groups( $groups ) {
		if ( ! is_array( $groups ) ) {
			$groups = (array) $groups;
		}

		$this->global_groups = array_merge( $this->global_groups, $groups );
		$this->global_groups = array_unique( $this->global_groups );
	}

	function add_non_persistent_groups( $groups ) {
		if ( ! is_array( $groups ) ) {
			$groups = (array) $groups;
		}

		$this->no_mc_groups = array_merge( $this->no_mc_groups, $groups );
		$this->no_mc_groups = array_unique( $this->no_mc_groups );
	}

	function incr( $id, $n = 1, $group = 'default' ) {
		$key                 = $this->key( $id, $group );
		$mc                  =& $this->get_mc( $group );
		$this->cache[ $key ] = $mc->increment( $key, $n );

		return $this->cache[ $key ];
	}

	function decr( $id, $n = 1, $group = 'default' ) {
		$key                 = $this->key( $id, $group );
		$mc                  =& $this->get_mc( $group );
		$this->cache[ $key ] = $mc->decrement( $key, $n );

		return $this->cache[ $key ];
	}

	function close() {
		foreach ( $this->mc as $bucket => $mc ) {
			$mc->close();
		}
	}

	function delete( $id, $group = 'default' ) {
		$key = $this->key( $id, $group );

		if ( in_array( $group, $this->no_mc_groups ) ) {
			unset( $this->cache[ $key ] );

			return true;
		}

		$mc =& $this->get_mc( $group );

		$result = $mc->delete( $key );

		if ( isset( $this->stats['delete'] ) ) {
			++ $this->stats['delete'];
		}
		$this->group_ops[ $group ][] = "delete $id";

		if ( false !== $result ) {
			unset( $this->cache[ $key ] );
		}

		return $result;
	}

	function flush() {
		// Don't flush if multi-blog.
		if ( function_exists( 'is_site_admin' ) || defined( 'CUSTOM_USER_TABLE' ) && defined( 'CUSTOM_USER_META_TABLE' ) ) {
			return true;
		}

		$ret = true;
		foreach ( array_keys( $this->mc ) as $group ) {
			$ret &= $this->mc[ $group ]->flush();
		}

		return $ret;
	}

	function get( $id, $group = 'default', $force = false ) {
		$key = $this->key( $id, $group );
		$mc  =& $this->get_mc( $group );

		if ( isset( $this->cache[ $key ] ) && ( ! $force || in_array( $group, $this->no_mc_groups ) ) ) {
			if ( is_object( $this->cache[ $key ] ) ) {
				$value = clone $this->cache[ $key ];
			} else {
				$value = $this->cache[ $key ];
			}
		} else if ( in_array( $group, $this->no_mc_groups ) ) {
			$this->cache[ $key ] = $value = false;
		} else {
			$value = $mc->get( $key );
			if ( null === $value ) {
				$value = false;
			}
			$this->cache[ $key ] = $value;
		}

		if ( isset( $this->stats['get'] ) ) {
			++ $this->stats['get'];
		}

		$this->group_ops[ $group ][] = "get $id";

		if ( 'checkthedatabaseplease' === $value ) {
			unset( $this->cache[ $key ] );
			$value = false;
		}

		return $value;
	}

	function get_multi( $groups ) {
		/*
		format: $get['group-name'] = array( 'key1', 'key2' );
		*/
		$return = array();
		foreach ( $groups as $group => $ids ) {
			$mc =& $this->get_mc( $group );
			foreach ( $ids as $id ) {
				$key = $this->key( $id, $group );
				if ( isset( $this->cache[ $key ] ) ) {

					if ( is_object( $this->cache[ $key ] ) ) {
						$return[ $key ] = clone $this->cache[ $key ];
					} else {
						$return[ $key ] = $this->cache[ $key ];
					}

					continue;

				} else if ( in_array( $group, $this->no_mc_groups ) ) {
					$return[ $key ] = false;
					continue;
				} else {
					$return[ $key ] = $mc->get( $key );
				}
			}
// TODO: investigate this. commented out becuase $to_get was never defined
//			if ( $to_get ) {
//				$vals = $mc->get_multi( $to_get );
//				$return = array_merge( $return, $vals );
//			}
		}

		if ( isset( $this->stats['get_multi'] ) ) {
			++ $this->stats['get_multi'];
		}

		$this->group_ops[ $group ][] = "get_multi $id";
		$this->cache                 = array_merge( $this->cache, $return );

		return $return;
	}

	function key( $key, $group ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( false !== array_search( $group, $this->global_groups ) ) {
			$prefix = $this->global_prefix;
		} else {
			$prefix = $this->blog_prefix;
		}

		return preg_replace( '/\s+/', '', WP_CACHE_KEY_SALT . "$prefix$group:$key" );
	}

	function replace( $id, $data, $group = 'default', $expire = 0 ) {
		$key    = $this->key( $id, $group );
		$expire = ( $expire == 0 ) ? $this->default_expiration : $expire;
		$mc     =& $this->get_mc( $group );

		if ( is_object( $data ) ) {
			$data = clone $data;
		}

		$result = $mc->replace( $key, $data, false, $expire );
		if ( false !== $result ) {
			$this->cache[ $key ] = $data;
		}

		return $result;
	}

	function set( $id, $data, $group = 'default', $expire = 0 ) {
		$key = $this->key( $id, $group );
		if ( isset( $this->cache[ $key ] ) && ( 'checkthedatabaseplease' === $this->cache[ $key ] ) ) {
			return false;
		}

		if ( is_object( $data ) ) {
			$data = clone $data;
		}

		$this->cache[ $key ] = $data;

		if ( in_array( $group, $this->no_mc_groups ) ) {
			return true;
		}

		$expire = ( $expire == 0 ) ? $this->default_expiration : $expire;
		$mc     =& $this->get_mc( $group );
		$result = $mc->set( $key, $data, false, $expire );

		return $result;
	}

	function colorize_debug_line( $line ) {
		$colors = array(
			'get'    => 'green',
			'set'    => 'purple',
			'add'    => 'blue',
			'delete' => 'red'
		);

		$cmd = substr( $line, 0, strpos( $line, ' ' ) );

		$cmd2 = "<span style='color:{$colors[$cmd]}'>$cmd</span>";

		return $cmd2 . substr( $line, strlen( $cmd ) ) . "\n";
	}

	function stats() {
		$stats_text = '';
		foreach ( $this->mc as $bucket => $mc ) {
			$stats = $mc->getExtendedStats();
			foreach ( $stats as $key => $details ) {
				$stats_text .= 'memcached: ' . $key . "\n\r";
				foreach ( $details as $name => $value ) {
					$stats_text .= $name . ': ' . $value . "\n\r";
				}
				$stats_text .= "\n\r";
			}
		}

		return $stats_text;
	}

	function &get_mc( $group ) {

		$mc = $this->mc;

		if ( isset( $mc[ $group ] ) ) {
			return $mc[ $group ];
		}

		return $this->mc['default'];
	}

	function failure_callback( $host, $port ) {
	}

	function WP_Object_Cache() {
		global $memcached_servers;

		if ( isset( $memcached_servers ) ) {
			$buckets = $memcached_servers;
		} else {
			$buckets = array( '127.0.0.1' );
		}

		reset( $buckets );
		if ( is_int( key( $buckets ) ) ) {
			$buckets = array( 'default' => $buckets );
		}

		foreach ( $buckets as $bucket => $servers ) {
			$this->mc[ $bucket ] = new Memcache();
			foreach ( $servers as $server ) {
				list ( $node, $port ) = explode( ':', $server );
				if ( ! $port ) {
					$port = ini_get( 'memcache.default_port' );
				}
				$port = intval( $port );
				if ( ! $port ) {
					$port = 11211;
				}
				$this->mc[ $bucket ]->addServer( $node, $port, true, 1, 1, 15, true, array(
					$this,
					'failure_callback'
				) );
				$this->mc[ $bucket ]->setCompressThreshold( 20000, 0.2 );
			}
		}

		global $blog_id, $table_prefix;
		$this->global_prefix = '';
		$this->blog_prefix   = '';
		if ( function_exists( 'is_multisite' ) ) {
			$this->global_prefix = ( is_multisite() || defined( 'CUSTOM_USER_TABLE' ) && defined( 'CUSTOM_USER_META_TABLE' ) ) ? '' : $table_prefix;
			$this->blog_prefix   = ( is_multisite() ? $blog_id : $table_prefix ) . ':';
		}

		$this->cache_hits   =& $this->stats['get'];
		$this->cache_misses =& $this->stats['add'];
	}
}