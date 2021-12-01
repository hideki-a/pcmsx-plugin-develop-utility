# Develop Utility

PowerCMS Xのプラグイン開発を行う際によく利用するメソッドを集めたPHPクラスです。草案です。

```php
require_once LIB_DIR . 'Prototype' . DS . 'class.PTPlugin.php';
require_once '/path/to/plugins/DevelopUtility/lib/DevelopUtility.php';

class Sample extends PTPlugin {
    public function sample_callback_handler( $cb, $app, $obj ) {
        // 何らかの処理
        
        // DevelopUtilityのget_relation_from_idsメソッドを呼び出す
        $ids = DevelopUtility::get_relation_from_ids( $app, 'seminar', 'lecturer', [1,3] );

        // 何らかの処理
    }
}
```
