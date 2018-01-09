## The wrapper class to help WordPress CRUD for a single table.

### How To Use

```php
$mydb = new Db( 'mytable_without_wpdb_prefix' );
$all_data  = $mydb->get_all( $orderby = 'date', $order = 'ASC' );
$row_data  = $mydb->get_row( $column = 'id', $value = 102, $format = '%d', $output_type = OBJECT, $offset = 10 );
$columns   = $mydb->get_columns();
$get_by    = $mydb->get_by(
                          $columns     = array( 'id', 'slug' ),
                          $field       = 'id',
                          $field_value = 102,
                          $operator    = '=',
                          $format      = '%d',
                          $orderby     = 'slug',
                          $order       = 'ASC',
                          $output_type = OBJECT_K
                      );
 $get_wheres = $mydb->get_wheres(
                          $column      = '*',
                          $conditions  = array(
                                             'category' => $category,
                                             'id'     => $id
                                        ),
                          $operator    = '=',
                          $format      = array(
                                              'category' => '%s',
                                              'id' => '%d'
                                        ),
                          $orderby     = 'category',
                          $order       = 'ASC',
                          $output_type = OBJECT_K
                      );
 $insert_id = $mydb->insert( $data = array( 'title' => 'text', 'date' => date("Y-m-d H:i:s") ) );