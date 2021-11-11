<?php
class DevelopUtility {

    /**
     * リレーション元IDの取得
     *
     * 階層モデルなどのIDから参照元（リレーション元）のオブジェクトIDを取得する
     * （例：カテゴリIDからそのカテゴリを指定している記事のIDを取得する）
     *
     * @param Prototype $app
     * @param string $from_obj リレーション元モデル名
     * @param string $to_obj リレーション先モデル名
     * @param string|array $to_ids リレーション先オブジェクトID
     * @return array
     */
    public static function get_relation_from_ids( $app, $from_obj, $to_obj, $to_ids ): array {
        $stack = [];
        $result = [];

        if ( is_array( $to_ids ) ) {
            $relation_terms['to_id'] = [ 'IN' => $to_ids ];
        } elseif ( strpos( $to_ids, ',' ) !== false ) {
            $relation_terms['to_id'] = [ 'IN' => explode( ',', $to_ids ) ];
        } else {
            $relation_terms['to_id'] = $to_ids;
        }
        $relation_terms['from_obj'] = $from_obj;
        $relation_terms['to_obj']   = $to_obj;
        $relation_objects = $app->db->model( 'relation' )->load( $relation_terms, [], 'from_id' );
        if ( count( $relation_objects ) ) {
            foreach ( $relation_objects as $object ) {
                $result[] = $object->from_id;
            }
        }

        return $result;
    }

    /**
     * リレーション先のオブジェクトIDの収集
     *
     * 指定されたリレーションのリレーション先オブジェクトIDを返す。
     *
     * @access private
     * @param Prototype $app Prototype
     * @param PADOMySQL $obj オブジェクト
     * @param string $relation_name リレーション名
     * @return array リレーション先オブジェクトID
     */
    public static function get_relation_to_ids( $app, $obj, $relation_name ) {
        $ids = [];
        $relations = $obj->_relations ? $obj->_relations : $app->get_relations( $obj );
        foreach ( $relations as $relation ) {
            if ( $relation->relation_name === $relation_name ) {
                $ids[] = $relation->relation_to_id;
            }
        }
        return $ids;
    }

    /**
     * リレーションの値を収集する
     *
     * リレーションオブジェクトから指定のリレーションのカラム値を取得する
     *
     * @access private
     * @param Prototype $app Prototype
     * @param array $relations リレーションオブジェクト（PADOMySQL）の配列
     * @param array $target_relations リレーション情報（[リレーション先 => リレーション先の値を取得するカラム]）
     * @return array $result 値の配列
     */
    private function get_relation_values( $app, $relations, $target_relations ): array {
        $result = [];

        foreach ( $relations as $relation ) {
            if ( array_key_exists( $relation->relation_to_obj, $target_relations ) ) {
                $target_column = $target_relations[ $relation->relation_to_obj ];
                $model = $relation->relation_to_obj;
                $object_id = $relation->relation_to_id;
                $object = $app->db->model( $model )->load( $object_id );
                if ( is_array( $target_column ) ) {
                    foreach ( $target_column as $key => $column ) {
                        if ( is_int( $key ) ) {
                            $result[] = $object->$column;
                        } else {
                            $deep_relations = $object->_relations ?
                                $object->_relations : $app->get_relations( $object );
                            if ( is_array( $column ) ) {
                                $values = $this->get_relation_values( $app, $deep_relations, $column );
                            } else {
                                $values = $this->get_relation_values( $app, $deep_relations, [ $key => $column ] );
                            }
                            foreach ( $values as $value ) {
                                $result[] = $value;
                            }
                        }
                    }
                } else {
                    $result[] = $object->$target_column;
                }
            }
        }

        $result = array_unique( array_filter( $result, function ( $value ) {
            return ! empty( $value );
        }) );
        return $result;
    }

    /**
     * リレーションの差分抽出
     *
     * 指定された条件のリレーションについて、追加・変更なし・削除のID（リレーション先のオブジェクトID）を算出する。
     *
     * @access private
     * @param Prototype $app Prototype
     * @param PADOMySQL $obj 変更後のオブジェクト
     * @param PADOMySQL $original 変更前のオブジェクト
     * @param string $relation_name リレーション名
     * @param string $relation_to_model_name リレーション先のモデル名
     * @return object 追加・変更なし・削除のID（リレーション先のオブジェクトID）
     */
    public static function diff_relation( $app, $obj, $original, $relation_name, $relation_to_model_name ) {
        $org_relations = $original->_relations ? $original->_relations : $app->get_relations( $original );
        $new_relations = $obj->_relations ? $obj->_relations : $app->get_relations( $obj );

        $org_relation_to_ids = [];
        $new_relation_to_ids = [];
        foreach ( $org_relations as $relation ) {
            if ( $relation->relation_name === $relation_name ) {
                if ( $relation->relation_to_obj === $relation_to_model_name ) {
                    $org_relation_to_ids[] = $relation->relation_to_id;
                }
            }
        }
        foreach ( $new_relations as $relation ) {
            if ( $relation->relation_name === $relation_name ) {
                if ( $relation->relation_to_obj === $relation_to_model_name ) {
                    $new_relation_to_ids[] = $relation->relation_to_id;
                }
            }
        }

        $deleted = array_diff( $org_relation_to_ids, $new_relation_to_ids );
        $added = array_filter( $new_relation_to_ids, function ( $val ) use ( $org_relation_to_ids ) {
            if ( in_array( $val, $org_relation_to_ids, true ) === false ) {
                return $val;
            }
        } );
        $keeped = array_intersect( $org_relation_to_ids, $new_relation_to_ids );

        return (object) [
            'add'    => $added,
            'keep'   => $keeped,
            'delete' => $deleted,
        ];
    }

    /**
     * リレーションデータの更新
     *
     * @access private
     * @param Prototype $app Prototype
     * @param string $column_name リレーション元のカラム名（リレーション名）
     * @param string $from_id リレーション元のオブジェクトID
     * @param string $from_model リレーション元のモデル名
     * @param array $to_ids リレーション先のオブジェクトID
     * @param string $to_model リレーション先のモデル名
     * @return void
     */
    public static function update_relation_data( $app, $column_name, $from_id, $from_model, $to_ids, $to_model ) {
        $args = [
            'from_id'  => $from_id,
            'name'     => $column_name,
            'from_obj' => $from_model,
            'to_obj'   => $to_model,
        ];
        $app->set_relations( $args, $to_ids );
    }

    /**
     * 晦日の算出
     *
     * @param int $year 年
     * @param int $month 月
     * @return DateTime 晦日
     */
    public static function get_last_date_of_month( $year, $month ): DateTime {
        if ( $month + 1 > 12 ) {
            $year += 1;
            $month = 1;
        } else {
            $month += 1;
        }
        return new DateTime( $year . '-' . $month . '-00' );
    }

}
