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
- `app/src/SharedKernel/...` zawiera mechanizmy wspólne (np. EventLog, CommandBus, DomainEventsRecorder/Collector, integracje techniczne).

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
- Outside to **okno na świat bez side-effectów** — pozwala domenie odpytywać stan zewnętrzny (czas, przynależność do zespołu, hash tokenu, liczba wpisów w sesji itp.) zachowując enkapsulację wiedzy biznesowej. Domena sama decyduje, kiedy i jak wykorzystać te dane.
- Domena:
  - bierze czas z `{Aggregate}OutsideInterface::now()`
  - rejestruje eventy przez `{Aggregate}OutsideInterface::record(DomainEvent $event)`
  - odpytuje stan cross-BC (np. `isActiveClientMember()`, `isActiveMemberOfTeam()`) — nigdy nie modyfikuje obcych agregatów
  - odpytuje stan read-only w obrębie BC (np. `countEntriesInSession()`)
- Infrastructure dostarcza implementację Outside, która deleguje do mechanizmu SharedKernel (np. `DomainEventsRecorder`) oraz do query z innych BC.
- Konsekwencja: walidacje biznesowe (np. „retro może być wystartowane tylko przez członka zespołu") żyją w agregacie/fabryce/policy — nie w handlerze. Handler jest czystą orkiestracją.

### 6.1 Policy jako Domain Service
- Policy to odmiana Domain Service — nazwa nie zmienia natury.
- Preferuj „pure policy" gdy caller ma już potrzebne dane (np. `RetroPublicAccessPolicy` dostaje `status`, `inputClosedAt`, `now` jako parametry).
- Jeśli policy potrzebuje stanu z zewnątrz (read-only), wolno jej użyć Outside — nie twórz wrappera tylko po to, żeby policy wyglądała na „pure".
- Handler/Application przekazuje input domenowy (np. `sessionId`, `newCount`), a decyzja i reguła żyją w Domain.
- Przykład:
  ```
  handler: policy.assertCanAddEntries(sessionId, newCount)
  policy:  currentCount = outside.countEntriesInSession(sessionId)
           assert currentCount + newCount <= limit
  ```
### 6.2 Czas w domenie (twarda reguła)

- Wszystkie znaczniki czasu powstające w domenie (np. `createdAt`, `updatedAt`, `statusChangedAt`, `inputClosedAt`, `entryAddedAt`, `occurredAt`) są ustalane wewnątrz domeny na podstawie `{Aggregate}OutsideInterface::now()`.
- Warstwa Application/Ui nie przekazuje czasu do agregatów/fabryk wyłącznie po to, aby “wstrzyknąć teraz” (czyli nie dodajemy parametrów typu `DateTime $now`, `DateTime $createdAt` jako technicznego obejścia).
- Uzasadnienie: czas jest elementem reguł domenowych (moment utworzenia/zmiany), a kontrola czasu w testach odbywa się przez `FakeOutside` z deterministycznym `now()` zamiast rozsmarowywania “clock provider” po wielu miejscach.
- Wyjątek: daty będące częścią **jawnego inputu biznesowego** (np. termin podany przez użytkownika, data retrospekcji ustawiana w Team, zakres raportu) są przekazywane jako wartości domenowe i walidowane w domenie; nie są zastępowane przez `now()`.

## 7. Agregaty bez publicznych getterów
- Agregaty eksponują **zachowanie**, nie stan wewnętrzny.
- Publiczne gettery (np. `status()`, `joinTokenHash()`) są niedozwolone — stan agregatu to szczegół implementacyjny.
- Eventy domenowe są kontraktem zewnętrznym: jeśli świat zewnętrzny potrzebuje danych z agregatu, dostaje je przez event (np. `RetroSessionStarted` zawiera `clientId`, `teamId`, `startedBy`).
- Odczyt stanu do UI/API odbywa się przez Query (DBAL) zwracające DTO — nie przez gettery na agregacie.
- Wyjątek: prywatne/wewnętrzne metody pomocnicze (np. `private function status()`) są dozwolone, bo nie łamią enkapsulacji.

## 8. Eventy domenowe (kontrakt)
- Każdy event domenowy agregatu zawiera:
  - `{aggregate}Id` (np. `clientId`) jako typ `Id`
  - `occurredAt` jako `DateTime` (VO ze SharedKernel)
  - pola specyficzne dla zdarzenia
- Nazewnictwo pól jest spójne w całym BC (np. zawsze `clientId`).
- Brak wersjonowania eventów (MVP / KISS).

## 9. Transakcje i flush (jeden punkt)
- Flush/commit jest w jednym miejscu (centralna orkiestracja).
- Repozytoria robią `persist()`, nie robią `flush()`.
- Wyjątki tylko gdy są twardo uzasadnione i opisane w kodzie (preferowane w SharedKernel, nie w BC).
### 9.1 EventBus / Subscribery (twarda reguła)
- Subscribery (w tym `*Saga.php`) nie modyfikują encji ORM bezpośrednio.
- Jeśli reakcja na event wymaga zapisu do bazy lub zmiany stanu domeny, subscriber uruchamia dedykowany Command.
- Dzięki temu mechanizm eventów pozostaje in-memory i gotowy do przyszłego przełączenia na async/outbox bez zmiany logiki domeny.

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
