# Architecture

## 1. Cel i priorytety
- KISS ponad “spryt”.
- Czytelny podział warstw i kierunek zależności wymuszony narzędziami (Deptrac).
- CQRS-lite: odczyt i zapis rozdzielone technicznie i semantycznie.
- Stabilne kontrakty wewnętrzne (events/commands/queries) bez wersjonowania (MVP).
- Jeden oczywisty punkt transakcji/flush.
- Konwencja ponad konfigurację (minimum ręcznych wpisów w DI).

## 2. Struktura kontekstu (Bounded Context)
Źródła aplikacji są w `app/`.

Standardowy układ:
- `app/src/{BC}/Domain`
- `app/src/{BC}/Application`
- `app/src/{BC}/Infrastructure`
- `app/src/{BC}/Ui`
  - HTTP API: `app/src/{BC}/Ui/Http/...`
  - CLI: `app/src/{BC}/Ui/ConsoleCommands/...` (z czasem)

SharedKernel:
- `app/src/SharedKernel/...` zawiera mechanizmy wspólne (np. Clock, EventLog, CommandBus, DomainEventsRecorder/Collector, integration events, outbox i worker async).

## 3. Struktura katalogów (template)
```
app/src/{BoundedContext}/
├── Application/
│   └── {Aggregate}/
│       ├── Command/
│       │   └── {Action}/
│       │       ├── {Action}Command.php
│       │       └── {Action}CommandHandler.php
│       └── Query/
│           ├── {Aggregate}QueryInterface.php
│           └── Dto/
│               └── {Aggregate}Dto.php
├── Domain/
│   └── {Aggregate}/
│       ├── {Aggregate}.php
│       ├── Event/
│       │   └── {Aggregate}{Verb}.php
│       ├── Factory/
│       │   ├── {Aggregate}FactoryInterface.php
│       │   └── {Aggregate}Factory.php
│       ├── Outside/
│       │   └── {Aggregate}OutsideInterface.php
│       └── Repository/
│           ├── {Aggregate}RepositoryInterface.php
│           └── Exception/
│               └── {Aggregate}DoesNotExistException.php
├── Infrastructure/
│   ├── {Aggregate}/
│   │   ├── {Aggregate}Outside.php
│   │   ├── {Aggregate}Query.php
│   │   └── {Aggregate}Repository.php
│   └── Resource/
│       ├── config.yaml
│       └── Migrations/
│           └── Version{timestamp}.php
└── Ui/
    ├── Http/
    │   └── Api/
    │       └── {Aggregate}Controller.php
    ├── Input/
    │   └── {Action}Input.php
    └── ConsoleCommands/
        └── {Action}{Aggregate}Command.php
```

## 4. Warstwy i reguły zależności (twarde)
- Domain:
  - nie zna Infrastructure i Ui
  - dopuszczalne zależności: `SharedKernel\Domain` oraz techniczne minimum wymagane przez mapowanie (zgodnie z Deptrac)
- Application:
  - orkiestruje przypadki użycia
  - zna Domain i kontrakty (interfaces), nie zna detali Infrastructure
  - nie zna Outside
- Infrastructure:
  - implementuje interfejsy z Domain/Application (repo, query, outside)
  - zawiera DBAL/ORM, integracje, subscriber’y, transporty
- Ui:
  - adaptery wejścia (HTTP/CLI), walidacja, mapowanie Input -> Command, odpowiedź (HTTP)

Deptrac jest źródłem prawdy dla kierunku zależności.

## 5. CQRS-lite (kontrakt zespołowy)

### 5.1 Zapis (Commands)
- Zmiana stanu bytów domenowych = zawsze Command.
- ORM (Doctrine) używany do zmian stanu agregatów (persist/flush przez jeden punkt).
- Command:
  - niemutowalny DTO
  - bez logiki
- Handler:
  - orkiestruje
  - używa Factory/Repository
  - nie rejestruje eventów domenowych (to robi agregat przez Outside)

### 5.2 Odczyt (Queries)
- Odczyt danych = zawsze DBAL/SQL (żadnego ORM do query).
- Query zwraca DTO (nigdy encji).
- Query jest bez side-effectów.

### 5.3 Wyjątki techniczne
- Dopuszczalne są pojedyncze operacje DBAL/SQL “write” poza ORM dla spraw technicznych (np. `EventLogInterface::save` w SharedKernel).
- To wyjątek, nie norma w kontekstach biznesowych.

## 6. Outside pattern (twarda reguła)
- Outside jest zależnością warstwy Domain: agregatów, fabryk oraz serwisów domenowych (Domain Services / Policy).
- Application nie zna Outside i go nie wstrzykuje — handlery nigdy nie mają bezpośredniej zależności od OutsideInterface.
- Outside to **okno na świat bez side-effectów** — pozwala domenie odpytywać stan zewnętrzny (czas, stan uprawnień, skróty wartości, liczniki i limity itp.) zachowując enkapsulację wiedzy biznesowej. Domena sama decyduje, kiedy i jak wykorzystać te dane.
- Domena:
  - bierze czas z `{Aggregate}OutsideInterface::now()`
  - rejestruje eventy przez `{Aggregate}OutsideInterface::record(DomainEvent $event)`
  - odpytuje stan cross-BC (np. `{OtherContext}QueryInterface`) — nigdy nie modyfikuje obcych agregatów
  - odpytuje stan read-only w obrębie BC (np. `count{AggregateItems}()`)
- Infrastructure dostarcza implementację Outside, która deleguje do mechanizmów SharedKernel (np. `ClockInterface`, `DomainEventsRecorder`) oraz do query z innych BC.
- Konsekwencja: walidacje biznesowe żyją w agregacie/fabryce/policy — nie w handlerze. Handler jest czystą orkiestracją.

### 6.1 Policy jako Domain Service
- Policy to odmiana Domain Service — nazwa nie zmienia natury.
- Preferuj „pure policy" gdy caller ma już potrzebne dane domenowe, ale traktuj to jako preferencję, nie dogmat.
- Jeśli policy potrzebuje stanu z zewnątrz (read-only), wolno jej użyć Outside albo innego wspólnego mechanizmu domenowego read-only (np. `SharedKernel\Domain\Clock\ClockInterface`) — nie twórz wrappera i nie przepychaj danych przez caller tylko po to, żeby policy wyglądała na „pure".
- Handler/Application przekazuje input domenowy (np. `{aggregateId}`, `{newCount}`), a decyzja i reguła żyją w Domain.
- Jeśli policy podejmuje decyzję zależną od bieżącego czasu, może użyć domenowej zależności read-only (`Outside` albo `ClockInterface`) zamiast dostawać `now` jako techniczny argument od caller-a.
- Przykład:
  ```
  handler: policy.assertCanAddItems(aggregateId, newCount)
  policy:  currentCount = outside.countItems(aggregateId)
           assert currentCount + newCount <= limit
  ```
### 6.2 Czas w domenie (twarda reguła)

- Wszystkie znaczniki czasu powstające w domenie (np. `createdAt`, `updatedAt`, `statusChangedAt`, `occurredAt`) są ustalane wewnątrz domeny na podstawie `{Aggregate}OutsideInterface::now()` albo domenowego `ClockInterface` w policy/domain service bez własnego Outside.
- Produkcyjne implementacje `{Aggregate}OutsideInterface::now()` delegują do `ClockInterface`; nie tworzą własnego czasu przez lokalne `DateTime::now()`.
- Warstwa Application/Ui nie przekazuje czasu do agregatów/fabryk wyłącznie po to, aby “wstrzyknąć teraz” (czyli nie dodajemy parametrów typu `DateTime $now`, `DateTime $createdAt` jako technicznego obejścia).
- Uzasadnienie: czas jest elementem reguł domenowych (moment utworzenia/zmiany), a kontrola czasu w testach odbywa się przez `FakeOutside` z deterministycznym `now()` albo przez `MutableClock`.
- `ClockInterface` zwraca `SharedKernel\Domain\ValueObject\DateTime`. Produkcyjna implementacja to `SystemClock`, testowa implementacja to `MutableClock`.
- `DateTime::now()` zawsze tworzy wartość w UTC. Konstruktor `DateTime` normalizuje wejściowe `DateTimeImmutable` do UTC.
- `DateTime::fromStorageString()` interpretuje storage string jako UTC, a `DateTime::toStorageString()` zapisuje format `Y-m-d H:i:s` w UTC.
- Typ Doctrine `domain_datetime` normalizuje odczyt i zapis do UTC storage stringa.
- PHP runtime w kontenerze ma `date.timezone=UTC`.
- Backend i baza danych traktują timestampy jako UTC. Dotyczy to także kolumn `TIMESTAMP WITHOUT TIME ZONE`, które w MVP oznaczają "UTC wall time"; aplikacja ma jawnie normalizować zapis i odczyt do UTC.
- Techniczne timestampy infrastruktury też są UTC: EventLog, outbox publisher i worker używają `ClockInterface` oraz zapisują storage string UTC.
- PostgreSQL nie jest źródłem lokalnego czasu aplikacji. Bieżące timestampy produkcyjne mają pochodzić z aplikacyjnego clocka albo jawnego UTC w infrastrukturze.
- Timezone użytkownika nie jest przechowywany w DB na MVP. Backend nie utrzymuje preferencji strefy czasowej użytkownika i nie przelicza timestampów na lokalną strefę w modelu domenowym ani read modelach.
- Prezentacja lokalnego czasu jest odpowiedzialnością UI/przeglądarki.
- Zakresy dat będące częścią **jawnego inputu biznesowego** są przekazywane jako wartości domenowe i walidowane w domenie; nie są zastępowane przez `now()`. Jeśli taki zakres ma semantykę lokalną, UI wysyła do backendu zakres już przeliczony na UTC.

## 7. Agregaty bez publicznych getterów
- Agregaty eksponują **zachowanie**, nie stan wewnętrzny.
- Publiczne gettery (np. `status()`, `{secretHash}()`) są niedozwolone — stan agregatu to szczegół implementacyjny.
- Eventy domenowe są kontraktem zewnętrznym: jeśli świat zewnętrzny potrzebuje danych z agregatu, dostaje je przez event (np. `{Aggregate}{Verb}` zawiera `{aggregateId}` i pola potrzebne odbiorcom).
- Odczyt stanu do UI/API odbywa się przez Query (DBAL) zwracające DTO — nie przez gettery na agregacie.
- Wyjątek: prywatne/wewnętrzne metody pomocnicze (np. `private function status()`) są dozwolone, bo nie łamią enkapsulacji.

## 8. Eventy domenowe (kontrakt)
- Każdy event domenowy agregatu zawiera:
  - `{aggregate}Id` jako typ `Id`
  - `occurredAt` jako `DateTime` (VO ze SharedKernel)
  - pola specyficzne dla zdarzenia
- Nazewnictwo pól jest spójne w całym BC (np. zawsze `{aggregateId}`).
- Brak wersjonowania eventów (MVP / KISS).
- `DomainEvent` jest kontraktem synchronicznym, in-process. Jest rejestrowany przez domenę, zapisywany do EventLog i dispatchowany przez sync `EventBus` w transakcji `CommandBus`.
- `DomainEvent` nie jest kolejką async i nie jest kontraktem durable delivery między procesami.

### 8.1 Integration events (kontrakt)
- `IntegrationEvent` jest osobnym kontraktem od `DomainEvent`.
- Integration event służy do asynchronicznej komunikacji technicznej między modułami/procesami przez outbox.
- Integration event jest serializowany do JSON przez Symfony Serializer. Preferowane pola to prymitywy i proste struktury serializowalne bez custom normalizerów.
- `IntegrationEventPublisherInterface::publish()` nie dispatchuje eventu in-memory. Aktualna implementacja `DbalOutboxPublisher` zapisuje rekord do `shared.async_outbox`.
- `DbalOutboxPublisher` nadaje techniczne `event_id`, zapisuje `event_name` jako FQCN klasy eventu, payload JSON oraz `created_at` z `ClockInterface` jako UTC storage string.
- Jeśli sync saga tłumaczy `DomainEvent` na `IntegrationEvent`, robi to w Application i używa `IntegrationEventPublisherInterface`.

## 9. Transakcje i flush (jeden punkt)
- Flush/commit jest w jednym miejscu (centralna orkiestracja).
- Repozytoria robią `persist()`, nie robią `flush()`.
- Wyjątki tylko gdy są twardo uzasadnione i opisane w kodzie (preferowane w SharedKernel, nie w BC).
### 9.1 EventBus / Subscribery (twarda reguła)
- Subscribery (w tym `*Saga.php`) nie modyfikują encji ORM bezpośrednio.
- Jeśli reakcja na event wymaga zapisu do bazy lub zmiany stanu domeny, subscriber uruchamia dedykowany Command.
- Dzięki temu mechanizm eventów pozostaje in-memory i gotowy do przyszłego przełączenia na async/outbox bez zmiany logiki domeny.

### 9.2 Outbox i async consumption
- `shared.async_outbox` jest techniczną tabelą durable queue/state store dla integration events.
- Worker CLI `app:process-outbox` przetwarza outbox pollingiem. Opcje:
  - `--limit` — maksymalna liczba rekordów claimowanych w jednym batchu, domyślnie 50
  - `--once` — przetwarza jeden batch i kończy
  - `--sleep` — liczba sekund snu między pustymi przebiegami, domyślnie 5
- Worker claimuje pending batch atomowym `UPDATE ... FROM (SELECT ... FOR UPDATE SKIP LOCKED) ... RETURNING`.
- Rekord outboxa jest kandydatem do claimu, gdy `processed_at IS NULL`, `attempts < 5` oraz nie ma aktywnego claimu albo claim wygasł.
- Lease TTL wynosi 5 minut. Po wygaśnięciu lease inny worker może przeclaimować rekord.
- `attempts` jest zwiększane przy claimie outboxa. Po błędzie worker zapisuje skrócony `last_error`, czyści claim outboxa i zostawia rekord do retry, dopóki `attempts < 5`.
- Po wyczerpaniu 5 prób rekord nie jest dalej claimowany automatycznie. MVP nie ma osobnej dead letter queue.
- Worker denormalizuje event na podstawie `event_name`, sprawdza implementację `IntegrationEvent` i szuka pasujących handlerów w tagowanych async subscriberach.
- Async subscriber to serwis z tagiem `app.integration_event_subscriber`. Konwencja autoload obejmuje `src/*/Application/IntegrationEventSubscriber/*Subscriber.php`.
- Handler async subscribera to publiczna metoda `on*()` z jednym typowanym parametrem zgodnym z konkretnym `IntegrationEvent`.
- `shared.async_consumption` jest magazynem claimu i idempotency per `(event_id, subscriber, handler_method)`.
- Worker stosuje model claim-before-side-effect:
  - przed wywołaniem handlera próbuje atomowo wstawić albo przejąć rekord `async_consumption` ze statusem `processing`
  - jeśli rekord ma status `processed`, handler jest pomijany
  - jeśli claim należy do innego aktywnego workera, outbox dostaje błąd i wraca do retry
  - po sukcesie handlera worker oznacza consumption jako `processed` tylko przy zachowanym `claimed_by`
  - po wyjątku handlera worker usuwa własny claim consumption i zwalnia outbox do retry
- Outbox jest oznaczany jako `processed` dopiero po sukcesie wszystkich pasujących handlerów i tylko przy zachowanym ownership (`claimed_by`).
- Async subscriber może uruchomić `CommandBus`; jest to osobna transakcja procesu workera.
- Idempotency w `async_consumption` chroni przed ponownym wykonaniem handlera oznaczonego jako `processed`. Handler, który wykonuje zewnętrzne side effecty, nadal powinien być projektowany idempotentnie biznesowo na wypadek przerwania procesu po side effekcie, a przed oznaczeniem `processed`.
- Świadome ograniczenia MVP:
  - brak brokera wiadomości
  - brak Redis
  - brak `LISTEN/NOTIFY`
  - brak dead letter queue
  - worker używa polling zamiast sygnału pobudki

## 10. DI i konfiguracja
- Preferujemy konwencję ponad konfigurację (autoload, wzorce plików, minimalne ręczne wpisy).
- Ręczne aliasy/definicje tylko gdy:
  - jest więcej niż jedna implementacja
  - albo potrzebujesz jawnego wyboru/priorytetu
- Nie robimy `public: true` tylko pod testy.

## 11. Platform routes (konwencja `platform_`)
- Route-name z prefiksem `platform_` jest zarezerwowany dla platform-only endpointów.
- Platform routes:
  - nie wymagają `active_client_id` w sesji.
  - `TenantGuardSubscriber`: pomija tenant checks dla route-name `platform_*` (po cross-origin checks).
  - `PlatformAdminGuardSubscriber`: wymusza `session.is_platform_admin === true`; w przeciwnym razie 403.
- Flaga `session.is_platform_admin` ustawiana po udanym loginie (`PlatformAdminOnLoginSubscriber`) na podstawie allowlisty `app.platform_admin_emails`.
- Test architektoniczny (`PlatformRouteNamingTest`) pilnuje, że żaden route nie zawiera substring "platform" bez prefiksu `platform_`.

## 12. Test strategy (minimum)
- Domain unit: testujemy zachowanie agregatów z FakeOutside i deterministycznym czasem.
- Integration: infrastruktura (DB, query DBAL, event log, mapping) ma przynajmniej jeden sensowny test.
- E2E (Behat): przynajmniej jeden scenariusz “happy path” przez UI -> Application -> Domain -> Infrastructure.

### 12.1 Behat conventions (KISS)
- Scenariusze używają aliasów (czytelnych nazw), nie surowych UUID.
- Given: ustawia stan aplikacji wyłącznie przez Commandy (CommandBus/handlery), nigdy przez endpointy.
- When: wykonuje tylko endpointy (HTTP).
- Then: weryfikuje stan przez Query (DBAL/read model). Endpointy w Then są dopuszczalne tylko do asercji kodów HTTP / error mapping.
- Dla współdzielonych “Given” używamy:
  - `FixtureContext` (wspólne kroki aranżacji stanu)
  - `FixtureRegistry` (mapowanie alias -> fixture/Id)


## 13. Quality gates (przed merge)
Używamy komend z `Makefile` w głównym katalogu.
- `make cs-check`
- `make phpstan`
- `make deptrac-ci`
- `make test`
- `make behat` (jeśli dotyczy UI / flow E2E)
