# powercable

Lightswitch creates an array of unique integers when you specify the lowest integer, the highest integer and the volume of integers you require.


## Installation

Install the latest version with

```bash
$ composer require shortdark/powercable
```

## Basic Usage

```php
<?php

require_once 'vendor/autoload.php';

$test = new Shortdark\Powercable();

$test->endtime = '1556452800'; // UNIX timestamp for the end time

/*
 * Calculate the latest dates we can start work based on the end time
 */
$test->latestStartTime();
var_dump($test->image_message);

/*
 * Calculate whether someone can order a product that takes n days
 */
$test->earliestEndTime();
var_dump($test->workdays_boolean);

```



## Basic Usage in a Laravel Controller

```php
<?php

use App\Http\Controllers\Controller;
use Shortdark\Powercable;

class MyController extends Controller
{
    public function index(Powercable $power)
    {
        $power->latestStartTime();
        $result = $power->image_message;
        return view('index', compact('result'));
    }
    
}
```

Then, as $result is an array, to get a representation of the array you could have something like the following in the index.blade.php.

```php
{{ json_encode($result) }}
```

### Author

Neil Ludlow - <neil@shortdark.net> - <https://twitter.com/shortdark>