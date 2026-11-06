# phloxcz/rpc-api

[English](README.md) | **Česky**

**[JSON-RPC 2.0](https://www.jsonrpc.org/specification) server pro [Nette](https://nette.org).**

API kontrolery jsou běžné služby v DI kontejneru. Jejich metody, autorizaci a parametry popisují atributy PHP a nativní typy. Tabulka metod se kompiluje do DI kontejneru, takže se při běhu nic nereflektuje.

> ⚠️ Balíček se aktivně vyvíjí. API zatím není stabilní.

## Požadavky

PHP 8.4+ a [`phloxcz/api-core`](https://github.com/phloxcz/api-core/blob/master/README.cs.md), který popisuje kontrolery, autorizaci a typy parametrů.

## Instalace

```bash
composer require phloxcz/rpc-api
```

```neon
extensions:
    rpcApi: Phlox\RpcApi\DI\Extension

rpcApi:
    endpoints:
        api:                            # běží na /api
            namespace: App\ApiModule    # text nebo seznam; včetně vnořených jmenných prostorů
            authorize: true             # výchozí pro metody bez atributu
            maxBatchSize: 100
            accessControl:
                allowedOrigins: ['https://app.example.com']
                allowCredentials: true

services:
    - App\ApiModule\UserController
```

## Použití

```php
namespace App\ApiModule;

use Phlox\ApiCore\ApiController;

class UserController extends ApiController
{
    public function read(int $id): array
    {
        return ['id' => $id];
    }
}
```

```
POST /api

{"jsonrpc": "2.0", "method": "user.read", "params": {"id": 1}, "id": 1}
{"jsonrpc": "2.0", "result": {"id": 1}, "id": 1}
```

Parametry mohou být pojmenované nebo poziční. Názvy se musí přesně shodovat: `user.read`, ne `user/read` nebo `userread`.

### Názvy metod

`UserController::read()` je `user.read`. `#[Path]` přepíše kteroukoli část a dvě metody se stejným názvem na jednom endpointu selžou při kompilaci kontejneru:

```php
use Phlox\RpcApi\Attributes\Path;

#[Path('admin.user')]
class UserController extends ApiController
{
    #[Path('fetch')]
    public function read(): array { /* admin.user.fetch */ }
}
```

## Protokol

- Požadavek bez členu `id` je notifikace: spustí se a nic se neposílá zpět, ani chyba. `"id": null` je běžný požadavek.
- Dávka větší než `maxBatchSize` se odmítne celá. V rámci dávky neovlivní selhané volání ostatní.
- Chyby protokolu používají standardní kódy `-32700`, `-32600`, `-32601`, `-32602` a `-32603`. Chyby autorizace používají `401` a `403`.
- `data` z `ApiException` se sloučí přímo do objektu `error`, vedle `code` a `message`.
- HTTP stav je `200` pro cokoli s tělem a `204`, když tělo není.

Jiné požadavky než `POST` a `OPTIONS` na endpoint a všechny požadavky jinam propadnou do aplikace.

## DTO

Parametr může být DTO a metoda ho může vrátit. Viz [DTO](https://github.com/phloxcz/api-core/blob/master/README.cs.md#dto).

```php
public function save(CreateUser $input): UserOutput { /* ... */ }
```

```json
{"jsonrpc": "2.0", "method": "user.save", "params": {"input": {"name": "Ann"}}, "id": 1}
```

Neplatná data dostanou kód `-32602` se všemi problémy ve `fields`, například `input.name`.

## OpenRPC

Každý endpoint dostane dokument OpenRPC, vygenerovaný při kompilaci kontejneru ze stejných metod, podle kterých server běží. V debug režimu je zveřejněný jako metoda `rpc.discover`, kde ho hledá specifikace. Z čeho se skládá, popisuje [`phloxcz/api-core`](https://github.com/phloxcz/api-core/blob/master/README.cs.md#dokumentace).

```neon
rpcApi:
    endpoints:
        api:
            namespace: App\RpcModule
            documentation:
                expose: true        # ve výchozím stavu jen v debug režimu
                info: {title: Orders API, version: 2.1.0}
                servers:            # OpenRPC potřebuje absolutní URL, proto je ve výchozím stavu vynechané
                    - {name: production, url: 'https://api.example.com/api'}
```

Autorizace v OpenRPC nemá své místo, takže metoda vyžadující přihlášení nese `x-authorize` a `x-roles`, totéž v popisu a chyby `401` a `403`. Dokud je dokument zveřejněný, vlastní metoda s názvem `rpc.discover` selže při kompilaci kontejneru.

## CORS

Bez `allowedOrigins` se žádné CORS hlavičky neposílají. Origin mimo seznam je také nedostane. `['*']` pošle doslova `*`; kombinace s `allowCredentials: true` selže při kompilaci kontejneru, protože ji prohlížeče odmítají. Klient, který posílá hlavičku `Authorization`, tu credentials nepotřebuje. Pro cookies vyjmenujte originy.

## Konfigurace

| Klíč | Výchozí | Popis |
|---|---|---|
| `debug` | `%debugMode%` | Přidává interní zprávy do chybových odpovědí |
| `dateTimeFormats` | viz `ParameterBinder` | Přijímané formáty parametrů s datem |
| `endpoints.<name>.namespace` | povinné | Kontrolery obsluhované endpointem |
| `endpoints.<name>.authorize` | `true` | Zda metody bez atributu vyžadují přihlášení |
| `endpoints.<name>.maxBatchSize` | `100` | Největší počet volání v jedné dávce |
| `endpoints.<name>.accessControl` | bez CORS | `allowedOrigins`, `allowCredentials`, `allowMethods`, `allowHeaders`, `exposeHeaders`, `maxAge` |
| `endpoints.<name>.documentation` | generuje se, zveřejněná v debug režimu | viz [OpenRPC](#openrpc) |

## Přechod z abrad450/api-server-nette

Celý postup je v [`MIGRATION.cs.md`](https://github.com/phloxcz/api-core/blob/master/MIGRATION.cs.md). Tento balíček se týká:

Jmenné prostory:

| Staré | Nové |
|---|---|
| `AbraD450\ApiServer\ApiController` | `Phlox\ApiCore\ApiController` |
| `AbraD450\ApiServer\Common\Attributes\*` | `Phlox\ApiCore\Attributes\*` |
| `AbraD450\ApiServer\RPC\Attributes\Path` | `Phlox\RpcApi\Attributes\Path` |
| `AbraD450\ApiServer\RPC\ApiException` | `Phlox\ApiCore\ApiException` |
| `AbraD450\ApiServer\ApiConstructible` | `Phlox\ApiCore\ApiConstructible` |

Změny, které je potřeba v existujícím projektu zkontrolovat:

- **Konfigurace:** z `apiServer: rpc: <name>` je `rpcApi: endpoints: <name>`.
- **Autorizace je ve výchozím stavu zapnutá.** Veřejné metody označte `#[AllowAnonymous]`, nebo u endpointu nastavte `authorize: false`.
- **`#[Authorize(['a', 'b'])]` teď znamená a nebo b, takže pustí víc uživatelů než dřív.** Kde jsou potřeba obě role, napište `#[Authorize(['a', 'b'], all: true)]`. Atributy na třídě a metodě musí dál platit společně.
- **Zděděné metody už nejsou vystavené.** Metodu ze společného základního kontroleru je potřeba přepsat v konkrétním kontroleru.
- **`#[NonApi]` teď v RPC funguje.** Metody s ním se dřív daly volat.
- **CORS:** `allowedOrigins: ['*']` s `allowCredentials: true` musí být nahrazené seznamem originů.
- **Názvy metod se musí přesně shodovat.** Varianty jako `user/read` už se nepřeloží.
- **Přísnější parametry:** `"1.5"` pro `int` a `true` pro `string` se odmítnou.
- **Přísnější protokol:** neúspěšné notifikace už neposílají chybu a `"id": null` už není notifikace.
- **Bootstrap běží před API.** Kód za `createContainer()` se teď spouští i pro požadavky na API, včetně věcí jako přesměrování nebo režim údržby. `index.php` musí volat `Application::run()`.
- **Data a časy:** samotné časové pásmo, jako `x` nebo `utc`, se teď odmítne, místo aby znamenalo aktuální čas.
- **Dotazovací jazyk** se přesunul do [`phloxcz/api-query`](https://github.com/phloxcz/api-query/blob/master/README.cs.md#přechod-z-abrad450api-server-nette), včetně `$this->processor`.

## Licence

[MIT](LICENSE)
