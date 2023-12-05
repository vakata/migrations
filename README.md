# migrations

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)

PHP database migrations.

## Install

Via Composer

``` bash
$ composer require vakata/migrations
```

## Usage

Prepare a directory for all migrations. A migration consists of a folder with 3 files inside:
 - schema.sql - needed schema modifications
 - data.sql - optional data to insert / update / delete
 - uninstall.sql - optional statements to fully revert this migration

Migrations are either base or app migrations - base migrations are applied first, then app migrations. Some migrations are behind a feature flag - if not passed to the constructor they will not be applied. Example structure:
```
 |-migrations
   |- base
   |  |- _core
   |     |- 000
   |        |- schema.sql
   |        |- data.sql
   |        |- uninstall.sql
   |- app
      |- _core
      |  |- 000
      |  |  |- schema.sql
      |  |  |- data.sql
      |  |  |- uninstall.sql
      |  |- 001
      |     |- schema.sql
      |- feature1
         |- 000
            |- schema.sql
            |- uninstall.sql
```

Create the neccessary table, for example:
```sql
-- postgre
CREATE TABLE IF NOT EXISTS migrations (
  migration SERIAL NOT NULL,
  package varchar(255) NOT NULL,
  installed timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ,
  removed timestamp DEFAULT NULL ,
  PRIMARY KEY (migration)
);
-- mysql
CREATE TABLE IF NOT EXISTS migrations (
  migration bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  package varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  installed datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed datetime DEFAULT NULL,
  PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ;
```

``` php
$migrations = new \vakata\migrations\Migrations(
    new \vakata\database\DB('<connection-string-here>'),
    'path/to/migrations/folder',
    [ 'feature1', 'feature2' ] // optional feature flags
);
$migrations->up();
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email github@vakata.com instead of using the issue tracker.

## Credits

- [vakata][link-author]
- [All Contributors][link-contributors]

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information. 

[ico-version]: https://img.shields.io/packagist/v/vakata/migrations.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/vakata/migrations.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/vakata/migrations
[link-downloads]: https://packagist.org/packages/vakata/migrations
[link-author]: https://github.com/vakata
[link-contributors]: ../../contributors
[link-cc]: https://codeclimate.com/github/vakata/migrations

