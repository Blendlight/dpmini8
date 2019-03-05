# Drupal Mini (Drupal8 Copy)

## Symfony Components
- Request
- Response
- 


## Notes



#### Run time PSR4 autoload
```php
$loader = require_once('vendoer/autoload.php');
$loader->addPsr4($namespace, $path);
$loader->addPsr4('Drupal\\', BASE_PATH.'\drupal\core\lib\....');
```
Add Modules src folder
```php
$modules = [
    'adnan',
    'khan',
    'manan',
    'moin'
];

foreach($modules as $module)
{    
    $loader->addPsr4("Drupal\\{$module}\\", BASE_DIR.'/core/modules/'.$module.'/src/');
}
```

