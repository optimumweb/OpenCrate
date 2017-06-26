<?php

namespace OpenCrate;

class Model
{
    public static $pdo = null;

    public static $db_table    = null;
    public static $primary_key = null;

    public function __construct($properties = [])
    {
        $properties = array_merge((array) $this->default_properties(), (array) $properties);
        $this->update($properties);
        return $this;
    }

    public function update($properties = [])
    {
        if ( !empty($properties) && is_array($properties) ) {
            foreach ( $properties as $property => $value ) {
                if ( property_exists($this, $property) ) {
                    $this->$property = $value;
                }
            }
        }
        return $this;
    }

    public function __get($property)
    {
        if ( property_exists($this, $property) ) {
            return $this->$property;
        }
    }

    public function __set($property, $value)
    {
        if ( property_exists($this, $property) ) {
            $this->$property = $value;
        }
    }

    public function default_properties()
    {
        return [];
    }

    public function save()
    {
        if ( $pdo = self::get_PDO() ) {

            $pk = static::$primary_key;

            if ( $this->$pk === null ) {
                if ( property_exists($this, 'created_at') ) {
                    $this->created_at = time();
                }
            } else {
                if ( property_exists($this, 'updated_at') ) {
                    $this->updated_at = time();
                }
            }

            $properties = get_object_vars($this);

            $set = [];
            $values = [];
            foreach ( $properties as $property => $value ) {
                if ( $property !== $pk ) {
                    $set[] = sprintf("%s = :%s", $property, $property);
                    $values[$property] = $value;
                }
            }
            $set = implode(", ", $set);

            if ( $this->$pk === null ) {

                $stmt = $pdo->prepare(sprintf("INSERT INTO %s SET %s", static::$db_table, $set));
                $res = $stmt->execute($values);

                if ( $res && $last_insert_id = $pdo->lastInsertId() ) {
                    $this->$pk = $last_insert_id;
                    return $this->$pk;
                } else {
                    throw new \Exception(sprintf("Could not save %s (%s)", get_class($this), $stmt->errorInfo()));
                }

            } else {

                $stmt = $pdo->prepare(sprintf("UPDATE %s SET %s WHERE %s = %s LIMIT 1", static::$db_table, $set, $pk, $this->$pk));
                return $stmt->execute($values);

            }

        } else {
            throw new \Exception("No database available");
        }
    }

    public static function find($id)
    {
        if ( $pdo = self::get_PDO() ) {
            $stmt = $pdo->prepare(sprintf("SELECT * FROM %s WHERE id = :id LIMIT 1", static::$db_table));
            $stmt->execute([ 'id' => $id ]);
            if ( $stmt->rowCount() == 1 ) {
                return new static($stmt->fetch());
            }
        } else {
            throw new \Exception("No database available");
        }
    }

    public static function where($property, $value, $options = [])
    {
        if ( $pdo = self::get_PDO() ) {

            $options = array_merge([
                'first'    => false,
                'limit'    => null,
                'order_by' => null
            ], $options);

            if ( $options['first'] && $options['limit'] === null ) {
                $options['limit'] = 1;
            }

            $order_by_str = isset($options['order_by']) ? sprintf("ORDER BY %s", $options['order_by']) : "";
            $limit_str    = isset($options['limit'])    ? sprintf("LIMIT %s", $options['limit'])       : "";

            $stmt = $pdo->prepare(sprintf("SELECT * FROM %s WHERE %s = :value %s %s", static::$db_table, $property, $order_by_str, $limit_str));
            $stmt->execute([ 'value' => $value ]);

            if ( $options['first'] ) {
                return new static($stmt->fetch());
            } else {
                $records = [];
                foreach ( $stmt as $record ) {
                    $records[] = new static($record);
                }
                return $records;
            }

        } else {
            throw new \Exception("No database available");
        }
    }

    public static function generate_sid($prefix = '', $length = 25)
    {
        $length = $length - strlen($prefix);
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $chars_len = strlen($chars);
        $sid = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $sid .= $chars[rand(0, $chars_len - 1)];
        }
        return $prefix . $sid;
    }

    public static function get_PDO()
    {
        if ( self::$pdo === null ) {

            if ( !defined('DB_DSN') ) {

                if ( !defined('DB_TYPE') ) {
                    define('DB_TYPE', 'mysql');
                }

                if ( !defined('DB_HOST') ) {
                    define('DB_HOST', 'localhost');
                }

                if ( !defined('DB_CHARSET') ) {
                    define('DB_CHARSET', 'utf8');
                }

                if ( defined('DB_NAME') ) {
                    define('DB_DSN', sprintf('%s:host=%s;dbname=%s;charset=%s', DB_TYPE, DB_HOST, DB_NAME, DB_CHARSET));
                }

            }

            if ( defined('DB_DSN') && defined('DB_USER') && defined('DB_PASS') ) {

                if ( extension_loaded('pdo') && class_exists('PDO') && defined('PDO::ATTR_DRIVER_NAME') ) {

                    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
                    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                    self::$pdo = $pdo;

                } else {
                    throw new \Exception("PDO not available!");
                }

            }

        }

        if ( self::$pdo !== null && self::$pdo instanceof PDO ) {
            return self::$pdo;
        }
    }
}
