# Architecture

Ten dokument jest kanonicznym źródłem reguł, wzorców, ograniczeń i gwarancji architektonicznych. Pełne tłumaczenie: [architecture.en.md](architecture.en.md). Aktualne zachowanie platformy, kontrakty HTTP i szczegóły runtime opisuje [platform.md](platform.md) (po angielsku). Quality gates i workflow: [instrukcje dla agentów i autorów zmian](instructions-for-agents.md).

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
  - CLI: `app/src/{BC}/Ui/ConsoleCommands/...`

Autoload klas produkcyjnych używa PSR-4 `App\\` -> `app/src/`. Aktualne konteksty biznesowe to `Client` i `User`; mechanizmy współdzielone należą do `SharedKernel`.

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

Deptrac (`app/deptrac.php`) wymusza kierunki zależności i granice wszystkich BC. SharedKernel nie jest BC i zachowuje osobne reguły Clock/Core/ValueObject/Application/Infrastructure/Ui. Clock może zależeć od ValueObject, Core od Clock, a ValueObject od Core i biblioteki UUID. SharedKernel/Application może korzystać z Application, publicznych kontraktów i Domain kontekstów biznesowych, ale nie z Outside (§6). SharedKernel/Infrastructure dodatkowo ma dostęp do ich Outside i Infrastructure, a SharedKernel/Ui — do Outside i Ui.

Kontrakt cross-BC (A i B to różne konteksty biznesowe):
- Sync: `Infrastructure A -> QueryInterface, Command lub DTO B` jest dozwolone.
- Async: `Application/IntegrationEventSubscriber A -> IntegrationEvent B` jest dozwolone. To jedyny wyjątek cross-BC dla Application; subscriber zachowuje własne dozwolone zależności Application, w tym zakaz dostępu do Outside.
- `Domain`, zwykłe `Application` i `Ui` A nie importują żadnej klasy ani interfejsu B, również `IntegrationEvent`. Dla komunikacji sync konsument definiuje własny port; obcy kontrakt zna wyłącznie adapter Infrastructure.
- `Infrastructure A -> repository (również interface), handler, implementacja, service lub IntegrationEvent B` jest zabronione. Subscriber nie otrzymuje dostępu do obcych kontraktów sync ani innych klas obcego BC.
- Obcy service interface nie jest automatycznie publicznym kontraktem. Wymaga istniejącego, konkretnie uzasadnionego wyjątku: nazwanych konsumenta i interfejsu, uzasadnienia oraz testu. Aktualnie nie ma takich wyjątków; `ValueHasherServiceInterface` i `UserNotificationSenderServiceInterface` są używane tylko wewnątrz User.

Publiczne kontrakty sync mają namespace `App\{BC}\Application\{moduł}\…\Query\*QueryInterface`, `…\Command\**\*Command` lub `…\Query\Dto\*Dto`. Pod Application jest co najmniej jeden segment modułu/agregatu; QueryInterface leży bezpośrednio w Query, a DTO bezpośrednio w Query/Dto. `**` pod Command oznacza zero lub więcej segmentów: Command może leżeć bezpośrednio w Command albo w podnamespace przypadku użycia. Katalog Command nie udostępnia handlerów, a sufiks `Interface` nie udostępnia repozytoriów i serwisów.

Publiczne kontrakty async to `App\{BC}\Application\IntegrationEvent\**\*IntegrationEvent`, a ich konsumenci cross-BC to `App\{BC}\Application\IntegrationEventSubscriber\*Subscriber`. W przypadku eventów `**` oznacza zero lub więcej segmentów. Subscriber musi leżeć bezpośrednio w `Application/IntegrationEventSubscriber`, zgodnie z płaską konwencją rejestracji DI (§8.1); subscriber w podnamespace nie otrzymuje wyjątku cross-BC. Wymagane są jednocześnie właściwy namespace i sufiks nazwy klasy. Helper w katalogu IntegrationEvent ani Subscriber poza IntegrationEventSubscriber nie uzyskuje publicznego dostępu. Kontrakty sync, integration events i subscribery są wydzielone z ogólnego Application. Outside pozostaje wydzielone z Domain, aby także własne Application i subscribery nie mogły go używać (§6).

Każdy katalog pierwszego poziomu `app/src/{BC}/`, z wyjątkiem `SharedKernel`, jest automatycznie objęty tym samym kontraktem. Nowy BC zgodny ze standardowym układem (§2–3) nie wymaga dopisywania warstw, wyjątków między parami kontekstów ani wpisu do rejestru. Pliki bezpośrednio w `app/src/`, takie jak `Kernel.php`, nie są BC.

### 4.1 Odczyt danych z obcego kontekstu (ACL)
- Kontekst nigdy nie pisze raw SQL/DBAL do tabel, których nie jest właścicielem.
- Jeśli kontekst A potrzebuje danych z kontekstu B, definiuje własny port odczytu (dla Domain: własny Outside). Adapter tego portu w Infrastructure A korzysta z publicznego `QueryInterface` kontekstu B. Domain nie odwołuje się bezpośrednio do obcego Query.
- Kontekst A definiuje własne DTO i mapuje dane z DTO kontekstu B — nie reeksportuje obcych DTO wyżej niż warstwa Infrastructure (Anti-Corruption Layer).
- Zaleta monolitu modularnego: zależność jest compile-time, bez serializacji i sieci, a granice kontekstów są jawne w namespace'ach i adapterach.
- Przykład: `User/Infrastructure/Tenant/ActiveMembershipsQuery` implementuje własny `ActiveMembershipsQueryInterface`, czyta przez `ClientMemberQueryInterface` i `ClientQueryInterface` z Client i mapuje `ClientMemberDto` oraz nazwę z `ClientDto` na własny `ActiveMembershipDto`. Deptrac sprawdza granice zależności; właściciela tabel SQL i poprawność semantyczną mapowania nadal sprawdzamy w review.
- Przykład w drugą stronę: `Client/Infrastructure/UserAccount/UserAccountQuery` implementuje własny `UserAccountQueryInterface` Client, czyta przez `UserQueryInterface` z User i zwraca wartości `Email`/`Id` zamiast `UserDto`. Korzystają z niego `ClientInvitationOutside` (e-mail działającego użytkownika i istnienie członkostwa osoby o danym e-mailu) oraz lista zaproszeń zalogowanego użytkownika.

### 4.2 Przypadek użycia zapisujący cross-BC
- Jeśli przypadek użycia w kontekście A musi uruchomić zapis należący do kontekstu B, Application kontekstu A zależy od własnego portu.
- Implementacja tego portu leży w Infrastructure kontekstu A. Adapter może wywołać publiczny Command kontekstu B przez `CommandBus` i odczytać wynik przez publiczny `QueryInterface` kontekstu B.
- W komunikacji sync Domain, Application i Ui kontekstu konsumującego nie importują żadnych klas obcego BC. Szczegóły obcego kontraktu pozostają w adapterze Infrastructure. Osobny wyjątek async dotyczy wyłącznie subscriberów integration events (§8.1).
- Adapter nie wywołuje obcego handlera ani repozytorium.
- W Demo Client jest właścicielem klientów, członkostw i zaproszeń, a User — tożsamości użytkownika i uwierzytelniania. Te granice obowiązują także przy orkiestracji cross-BC.

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
- Handlery Command domyślnie zwracają `void` i są uruchamiane przez `CommandBus::dispatch()`.
- Gdy bieżąca operacja musi zwrócić minimalny wynik biznesowy, Command może implementować `CommandWithResultInterface<TResult>` i być uruchomiony przez `CommandBus::dispatchWithResult()`.
- Wynik z `dispatchWithResult()` wraca dopiero po udanym flushu, obsłudze eventów i commit.
- Wynik Command nie może być agregatem, encją ani read modelem.

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
  - odpytuje stan cross-BC przez własny Outside; dopiero jego adapter Infrastructure zna `{OtherContext}QueryInterface` — nigdy nie modyfikuje obcych agregatów
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
- Model domenowy i read modele operują na UTC, bez przeliczania na lokalną strefę użytkownika.
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
- Integration event służy do asynchronicznej komunikacji technicznej między modułami/procesami przez outbox. Jest publicznym kontraktem async BC publikującego; w obcym BC może go importować wyłącznie `Application/IntegrationEventSubscriber` zgodny z konwencją z §4. Zwykłe Application, Domain, Ui i Infrastructure nie importują obcego eventu.
- Integration event jest serializowany do JSON przez Symfony Serializer. Preferowane pola to prymitywy i proste struktury serializowalne bez custom normalizerów.
- `IntegrationEventPublisherInterface::publish()` nie dispatchuje eventu in-memory. Aktualna implementacja `DbalOutboxPublisher` zapisuje rekord do `shared.async_outbox`.
- Jeśli sync saga tłumaczy własny `DomainEvent` na własny `IntegrationEvent`, robi to w Application i używa `IntegrationEventPublisherInterface`.
- Jeśli celem reakcji sync sagi jest async publish, saga nie uruchamia `CommandBus`; publikuje `IntegrationEvent` przez publisher.
- Krótki przykład: `ClientInvitationSaga` w Application Client tłumaczy własny `ClientInvitationCreated` na własny `ClientInvitationCreatedIntegrationEvent` przez publisher; nie wywołuje bezpośrednio User. [Flow powiadomienia](platform.md#43-invitation-notifications) opisuje konsumenta i dostarczenie.
- Konwencje DI:
  - sagi sync: `src/*/Application/**/Saga/*Saga.php` z tagiem `app.saga`, wywoływane przez sync `EventBus`
  - async subscribery: `src/*/Application/IntegrationEventSubscriber/*Subscriber.php` z tagiem `app.integration_event_subscriber`, wywoływane przez worker outboxa
- Handler async subscribera to publiczna metoda `on*()` z jednym typowanym parametrem zgodnym z konkretnym `IntegrationEvent`.

## 9. Transakcje i flush (jeden punkt)
- `CommandBus` jest właścicielem transakcji: uruchamia handler, wykonuje jeden ORM flush, zapisuje zebrane eventy do EventLog i dispatchuje je przez sync EventBus, a następnie wykonuje commit.
- Repozytoria robią `persist()`, nie robią `flush()`.
- Zmiany agregatów, EventLog i zapisy outboxa wykonywane w tej transakcji współdzielą commit/rollback. Błąd przed commit nie może pozostawić częściowego zapisu przypadku użycia.
- Efekty poza bazą (np. plik lub sesja HTTP) nie są objęte rollbackiem DB. Ui może udostępnić wynik zatwierdzonej zmiany dopiero po powrocie z `CommandBus`.
- Wyjątki tylko gdy są twardo uzasadnione i opisane w kodzie (preferowane w SharedKernel, nie w BC).
- Konkretne granice atomowości onboardingu i akceptacji: [platform.md §4](platform.md#4-flows-and-implementation).

### 9.1 EventBus / Subscribery (twarda reguła)
- Subscribery (w tym `*Saga.php`) nie modyfikują encji ORM bezpośrednio.
- Jeśli reakcja na event wymaga zmiany stanu domeny, subscriber uruchamia dedykowany Command. Techniczny zapis integration eventu do outboxa odbywa się przez publisher (§8.1), zgodnie z wyjątkiem DBAL (§5.3).
- Dzięki temu mechanizm eventów pozostaje in-memory i gotowy do przyszłego przełączenia na async/outbox bez zmiany logiki domeny.

### 9.2 Outbox i gwarancje konsumpcji async
- `shared.async_outbox` jest trwałym magazynem integration events. Publikacja w transakcji `CommandBus` jest atomowa ze zmianą domenową (§9); konsument przetwarza zatwierdzone rekordy w osobnym procesie.
- Claim rekordu musi być atomowy. Lease pozwala przejąć porzuconą pracę, a potwierdzenie przetworzenia wymaga zachowania ownership (`claimed_by`).
- Konsumpcja stosuje claim-before-side-effect. `shared.async_consumption` identyfikuje wykonanie przez `(event_id, subscriber, handler_method)`; handler oznaczony jako `processed` jest pomijany. Aktywny claim innego workera nie pozwala na równoległe wykonanie tego samego handlera.
- Outbox jest oznaczany jako `processed` dopiero po sukcesie wszystkich pasujących handlerów i przy zachowanym ownership. Błąd handlera zwalnia jego własny claim i pozwala na retry w granicach polityki runtime.
- Async subscriber może uruchomić `CommandBus`; jest to osobna transakcja procesu workera.
- Nie ma gwarancji exactly-once dla zewnętrznych efektów. Przerwanie procesu po efekcie, a przed oznaczeniem `processed`, może spowodować ponowne wykonanie. Handler musi zapewniać idempotencję biznesową; rejestr konsumpcji nie zastępuje tej ochrony.
- Parametry retry/lease, polling i ograniczenia bieżącej implementacji: [runtime outboxa](platform.md#44-outbox-runtime).

### 9.3 Migracje
- Migracje należą do właściciela schematu i są przechowywane w `app/src/{BC}/Infrastructure/Resource/Migrations/`; migracje mechanizmów współdzielonych należą do `SharedKernel`.
- Namespace'y migracji są rejestrowane centralnie w konfiguracji Doctrine Migrations.
- Zakres generowania i wykonania migracji przez Makefile opisuje [README](../README.md#dev-setup).

### 9.4 Blokowanie agregatów i świeżość stanu
- Mutacje wymagające serializacji przejść stanu muszą odczytać świeży agregat z blokadą w krótkiej transakcji `CommandBus`. W Demo `ClientInvitationRepository` używa ORM `LockMode::PESSIMISTIC_WRITE` (`SELECT ... FOR UPDATE`) jako pierwszego załadowania agregatu do UnitOfWork.
- Blokada trwa do commit/rollback. Konkurencyjna operacja czeka; po uzyskaniu blokady reguła domenowa ocenia zatwierdzony stan. Sama blokada nie zastępuje walidacji przejścia.
- Uwaga na identity map Doctrine: DQL z `setLockMode()` blokuje wiersz, ale nie odświeża encji już obecnej w UnitOfWork. Dotyczy to `getForClient()` i `getPendingForClientAndEmail()`, w odróżnieniu od blokującego `find()` w `get()`. Wcześniejszy odczyt ORM wymaga ponownego sprawdzenia świeżości stanu i kontraktu operacji; samo uzyskanie blokady nie gwarantuje odświeżenia obiektu.
- Blokada istniejącego wiersza nie chroni równoległego tworzenia: przed INSERT nie ma wiersza do zablokowania. Ograniczenia unikalności w DB pozostają wymaganym zabezpieczeniem niezmienników, obok walidacji domenowej.
- Zastosowanie do zaproszeń, indeksy i konsekwencje HTTP: [platform.md §4.2](platform.md#42-invitation-acceptance-and-concurrency).

## 10. DI i konfiguracja
- `app/config/services.yaml` jest rootem konfiguracji usług: importuje konwencyjny autoload oraz konfiguracje Infrastructure poszczególnych modułów.
- `app/config/services.autoload.yaml` rejestruje przez wzorce kontrolery, fabryki, repozytoria, query, Outside, handlery Command, komendy konsolowe, sagi, subscribery integration events, klasy `*Service` oraz pozostałe usługi Infrastructure.
- Szczegóły DI i mapowania Doctrine należące do modułu trzymamy w `app/src/{BC}/Infrastructure/Resource/config.yaml`. Root config pozostaje miejscem importów i naprawdę globalnych parametrów.
- Ręczny alias albo definicja są uzasadnione, gdy konwencja nie wystarcza: potrzebny jest jawny wybór implementacji, locator/iterator tagowanych usług, specjalny argument lub parametr środowiska, dekorator albo inna konfiguracja niewyrażalna samym wzorcem autoload.
- Nie robimy `public: true` tylko pod testy.

## 11. Trasy platformowe (konwencja `platform_`)
- Route-name z prefiksem `platform_` jest zarezerwowany dla endpointów platformowych. Żadna inna trasa nie może zawierać substringu `platform`; konwencję wymusza `PlatformRouteNamingTest`.
- Uprawnienia platformowe są oddzielone od członkostwa i roli w kliencie. Trasy platformowe wymagają administratora platformy, ale nie wymagają aktywnego klienta (`active_client_id`).
- `TenantGuardSubscriber` i `PlatformAdminGuardSubscriber` egzekwują ten podział. Wyjątek platformowy od kontroli tenantowych nie zwalnia z kontroli cross-origin.
- Dostęp do tras tenantowych jest jawnie konfigurowany; brak dopasowanej reguły oznacza odmowę (default deny).
- Stany sesji, źródło uprawnień i kolejność kontroli: [platform.md §2](platform.md#2-sessions-and-access-control).

### 11.1 Kontrakty HTTP i routingu
- Publiczny kontrakt endpointu obejmuje ścieżkę, metodę HTTP, input, status odpowiedzi i payload JSON. Zmiana któregokolwiek z tych elementów jest zmianą publicznej powierzchni HTTP i wymaga jawnego zakresu zadania oraz aktualizacji testów zachowania.
- Route name jest wewnętrznym kontraktem routingu i security, a nie częścią publicznego kontraktu HTTP. Nowe albo zmienione route names wymagają sprawdzenia prefiksu `platform_`, konfiguracji wymagań dostępu dla tras i allowlisty `TenantGuardSubscriber` oraz subscriberów reagujących na konkretną route.
- Katalog kontraktów: [platform.md §3](platform.md#3-httpapi-contracts).

## 12. Test strategy (minimum)
- Domain unit: testujemy zachowanie agregatów z FakeOutside i deterministycznym czasem.
- Integration: infrastruktura (DB, query DBAL, event log, mapping) ma sensowną automatyczną osłonę testową. Nie wymagamy osobnego testu mappingu dla każdego agregatu, jeśli mapping jest już realnie pokryty przez Behat lub inny test integracyjny przechodzący przez persist/flush/load. Dedykowany test mappingu dodajemy tylko wtedy, gdy mapping nie ma naturalnego pokrycia albo jest na tyle nietrywialny, że osobny test daje realną wartość.
- E2E (Behat): przynajmniej jeden scenariusz “happy path” przez UI -> Application -> Domain -> Infrastructure.

Testy w `app/tests/Architecture/BoundedContextDependenciesTest.php` uruchamiają Deptrac na tymczasowej kopii źródeł z niezmienionym `app/deptrac.php`. Sprawdzają aktualne adaptery, dozwolony dostęp Infrastructure do obcych Query/Command/DTO oraz subscriberów do obcych IntegrationEvent, odrzucanie pozostałych zależności cross-BC i nazw niezgodnych z konwencją oraz ograniczenia Outside i SharedKernel. Sztucznie dodany BC automatycznie otrzymuje ten sam kontrakt: może udostępniać i konsumować publiczne kontrakty, a niedozwolone zależności są odrzucane. Niepokryte zależności muszą powodować błąd kontroli Deptrac (`--report-uncovered --fail-on-uncovered`).

### 12.1 Behat conventions (KISS)
- Scenariusze używają aliasów (czytelnych nazw), nie surowych UUID.
- Given: ustawia stan aplikacji wyłącznie przez Commandy (CommandBus/handlery), nigdy przez endpointy.
- When: wykonuje tylko endpointy (HTTP).
- Then: weryfikuje stan przez Query (DBAL/read model). Endpointy w Then są dopuszczalne tylko do asercji kodów HTTP / error mapping.
- Konteksty jednej suite współdzielą w scenariuszu jedną przeglądarkę (`app.behat.kernel_browser` w `config/services_test.yaml`), więc sesja z logowania OTP w `UserContext` obowiązuje w krokach innego kontekstu.
- Dla współdzielonych “Given” używamy:
  - `FixtureContext` (wspólne kroki aranżacji stanu)
  - `FixtureRegistry` (mapowanie alias -> fixture/Id)
