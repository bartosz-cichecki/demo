# Plan modernizacji SharedKernel

## Cel

Celem modernizacji jest doprowadzenie demo do spójnego, neutralnego technicznie szkieletu aplikacji: jawne UTC w backendzie, stabilny SharedKernel, przewidywalne zdarzenia domenowe i integracyjne, gotowy mechanizm outbox dla pracy asynchronicznej oraz twardsze quality gates.

Ten plan opisuje wyłącznie mechanizmy techniczne. Nie zakłada nowego kontekstu biznesowego ani zmian w aktualnych flow produktu demo.

## Aktualny stan demo

- SharedKernel zawiera podstawowe kontrakty i implementacje dla `CommandBus`, `EventBus`, `EventLog`, zdarzeń domenowych, value objectów i typów Doctrine.
- `EventLog` zapisuje techniczny timestamp w UTC.
- Value object czasu i typ Doctrine nie wymuszają jeszcze pełnej normalizacji UTC przy tworzeniu, parsowaniu i zapisie wartości.
- Brakuje wspólnego `ClockInterface` oraz implementacji systemowej i testowej.
- Brakuje kontraktu `IntegrationEvent`, publishera integracyjnego, tabel outbox/consumption i worker command do przetwarzania asynchronicznego.
- Deptrac pilnuje podstawowych warstw, ale nie ma osobnej warstwy dla clocka ani wydzielonych reguł dla kontraktów PSR.
- Makefile ma główne quality gates: `cs-check`, `phpstan`, `deptrac-ci`, `test`, `behat`.
- Docker uruchamia PHP z lokalną strefą czasu zamiast UTC.

## Docelowy stan techniczny

- Backend i baza traktują timestampy jako UTC.
- `SharedKernel\Domain\Clock` dostarcza wspólny clock dla kodu domenowego i testów.
- Value object czasu potrafi jawnie tworzyć `now()` w UTC, parsować storage string jako UTC i formatować wartość do storage string UTC.
- Typ Doctrine dla czasu normalizuje odczyt i zapis do UTC.
- `DomainEvent` pozostaje mechanizmem synchronicznym, in-process.
- `IntegrationEvent` jest osobnym kontraktem dla zdarzeń asynchronicznych między modułami.
- Outbox zapisuje integration events do tabeli technicznej, a worker przetwarza je przez jawnie oznaczone async subscribery.
- Async consumption zapewnia idempotency i model claim-before-side-effect dla pojedynczego handlera.
- Deptrac zna osobną warstwę clocka i nie wymaga szerokich wyjątków.
- Dokumentacja architektury opisuje zasady neutralnymi przykładami i bez zależności od domeny produktu.

## Zakres do przeniesienia lub odtworzenia

- `ClockInterface`, `SystemClock`, `MutableClock`.
- UTC behavior w value object czasu i typie Doctrine.
- Rejestracja clocka w konfiguracji SharedKernel.
- Kontrakt `IntegrationEvent` i `IntegrationEventPublisherInterface`.
- Publisher zapisujący integration event do `shared.async_outbox`.
- Migracja dla `shared.async_outbox` i `shared.async_consumption`.
- Worker CLI do przetwarzania outboxa z pollingiem, lease TTL, limitem prób i idempotency per subscriber method.
- Konwencja DI dla async subscriberów.
- Deptrac: osobna warstwa clocka i ewentualnie wydzielone kontrakty PSR.
- Docker baseline dla `date.timezone=UTC`.
- Testy jednostkowe i integracyjne dla clocka, UTC storage, publishera i workera.
- Neutralna aktualizacja `docs/architecture.md` po wdrożeniu mechanizmów.

## Poza zakresem

- Nowy bounded context.
- Nowy agregat lub byt domenowy.
- Zmiany istniejących flow produktu demo.
- Import gotowych zdarzeń integracyjnych z innej domeny.
- Kolejka zewnętrzna, Redis, broker wiadomości lub `LISTEN/NOTIFY`.
- Dead letter queue.
- Obsługa strefy czasowej użytkownika jako preferencji zapisanej w bazie.
- Przebudowa `CommandBus`, `EventBus` lub aktualnego modelu transakcji, jeśli nie wymaga tego outbox.
- Aktualizacja pakietów UI, jeżeli nie jest wymagana przez osobne zadanie.
- Kopiowanie klas 1:1 bez neutralizacji nazw, testów i dokumentacji.

## Proponowana kolejność slice'ów

1. Clock i UTC ✅ DONE
   - dodać wspólny clock,
   - znormalizować value object czasu i typ Doctrine,
   - ustawić PHP timezone na UTC,
   - dodać testy niezależności od lokalnej strefy czasu,
   - zaktualizować Deptrac o warstwę clocka.
   - Status: dodano `ClockInterface`, `SystemClock` i `MutableClock`; `DateTime` VO normalizuje czas do UTC; typ Doctrine odczytuje i zapisuje UTC storage string; produkcyjne Outside oraz techniczny EventLog korzystają z `ClockInterface`; PHP runtime ma `date.timezone=UTC`; Deptrac ma warstwę dla clocka; testy oraz quality gates przeszły.

2. Baseline konfiguracji i reguł ✅ DONE
   - dodać neutralne konwencje autoload dla console commands i async subscriberów,
   - rozważyć wydzielenie kontraktów PSR w Deptrac,
   - utrzymać istniejące quality gates bez poszerzania zakresu funkcjonalnego.
   - Status: dodano neutralny autoload dla `src/*/Ui/ConsoleCommands/*Command.php`; dodano konwencję DI i tag `app.integration_event_subscriber` dla przyszłych `src/*/Application/IntegrationEventSubscriber/*Subscriber.php`; w Deptrac wydzielono `PsrContracts` z `External` i dopuszczono wyłącznie w aktualnie potrzebnych warstwach.

3. Integration event i outbox publisher ✅ DONE
   - dodać kontrakty integracyjne,
   - dodać publisher do `shared.async_outbox`,
   - dodać migrację tabel technicznych,
   - pokryć publisher testem integracyjnym.
   - Status: dodano neutralny kontrakt `IntegrationEvent`, `IntegrationEventPublisherInterface` oraz `DbalOutboxPublisher`; publisher zapisuje pojedyncze zdarzenie do `shared.async_outbox` bez dispatchu, zapisuje payload JSON i `created_at` jako UTC storage string z `ClockInterface`; migracja tworzy `shared.async_outbox` oraz `shared.async_consumption`; test integracyjny sprawdza payload, nazwę eventu, UTC timestamp i stan pending.

4. Worker async outbox ✅ DONE
   - dodać CLI worker,
   - obsłużyć claim batch, retry, ownership check i idempotency,
   - dodać neutralne testy dispatchu, błędu i ponownego przetwarzania.
   - Status: dodano neutralną komendę `app:process-outbox`; worker atomowo claimuje pending batch z lease TTL i limitem prób, denormalizuje `IntegrationEvent`, wykonuje pasujące tagowane async subscribery, stosuje `shared.async_consumption` dla idempotency per subscriber method, zapisuje skrócony błąd i zwalnia claim do retry oraz oznacza outbox jako processed wyłącznie przy zachowanym ownership; testy pokrywają claim batch, handler, idempotency, brak handlerów, retry po błędzie, pomijanie processed eventów i ownership check.

5. Dokumentacja architektury ✅ DONE
   - opisać UTC, clock, integration events, outbox i async consumption,
   - zachować neutralne nazwy i przykłady,
   - nie dokumentować pochodzenia zmian.
   - Status: zweryfikowano i uzupełniono `docs/architecture.md` o faktyczny stan UTC/storage timestampów, użycie `ClockInterface`, reguły Outside/time, rozdział `DomainEvent` vs `IntegrationEvent`, outbox publisher, worker `app:process-outbox`, async consumption, idempotency, retry oraz ownership check. W trakcie weryfikacji domknięto brakujące użycie clocka w produkcyjnych Outside i EventLog. Wykorzystano neutralne wnioski architektoniczne z projektu referencyjnego bez przenoszenia domeny, flow ani nazw.

6. Docker i Composer baseline ✅ DONE
   - potraktować pinning obrazów Docker jako osobny maintenance slice,
   - nie mieszać pinningu obrazów z wdrożeniem UTC,
   - aktualizować Composer tylko wtedy, gdy wynika to z potrzeb nowych mechanizmów albo quality gates.
   - Status: przypięto obrazy Docker do patch tags bez major upgrade’ów: PHP `8.3.30-fpm`, Composer `2.9.7`, PostgreSQL `16.13-alpine` i nginx `1.27.5-alpine`; prod Composer baseline uzupełniono minimalnie o runtime `symfony/translation` `7.4.*`, ponieważ prod `composer install --no-dev` musi obsłużyć aktywną konfigurację translatora; prod Doctrine proxy baseline ustawiono na `auto_generate_proxy_classes: 'FILE_NOT_EXISTS'`, zgodnie z finalnymi encjami domenowymi i wzorcem z projektu referencyjnego. `symfony.lock` i frontend dependencies nie wymagały zmian.

7. Pierwsze realne użycie async outboxa w BC User ✅ DONE
   - Status: rejestracja użytkownika emituje `UserRegistered`, saga publikuje `UserRegisteredIntegrationEvent` do outboxa; worker `app:process-outbox` wykonuje async subscriber i zapisuje techniczną notyfikację do pliku przez adapter infrastrukturalny. Behat potwierdza pełny flow end-to-end oraz brak duplikatu po ponownym uruchomieniu workera. Testowa konfiguracja DI nie używa już `public: true` dla clocka ani publishera integracyjnego tylko po to, aby pobierały je testy.

Po każdym zakończonym slice aktualizujemy status w tym dokumencie. Plan ma pokazywać aktualny stan prac, nie tylko pierwotne założenia.

## Ryzyka

- Zmiana timezone może ujawnić testy zależne od lokalnej strefy czasu.
- Normalizacja storage stringów może zmienić interpretację istniejących timestampów bez timezone.
- Worker asynchroniczny wymaga mocnych testów idempotency, aby uniknąć podwójnych side effectów.
- Deptrac może ujawnić istniejące zależności, które wcześniej były ukryte przez szersze warstwy.
- Migracje muszą współistnieć z aktualnym schematem `shared`.
- Dokumentacja musi pozostać neutralna i nie może zawierać nazw ani flow spoza demo.

## Kryteria DONE dla całego procesu

- Wszystkie timestampy generowane przez wspólne mechanizmy techniczne są tworzone lub normalizowane jako UTC.
- SharedKernel ma wspólny clock z testowalną implementacją.
- Typ Doctrine dla czasu ma testy odczytu i zapisu UTC.
- Integration events są osobnym kontraktem od domain events.
- Outbox i async consumption mają migracje, publishera, worker oraz testy.
- Async subscriber jest wykonywany co najwyżej raz dla danego eventu i metody handlera.
- Retry po błędzie jest jawny i testowany.
- Deptrac przechodzi w trybie CI.
- `make cs-check`, `make phpstan`, `make deptrac-ci`, `make test` przechodzą po każdym slice obejmującym kod.
- `make behat` przechodzi po slice, który może wpływać na zachowanie aplikacji.
- Dokumentacja architektury opisuje mechanizmy neutralnie.

## Checklist anty-przeciekowy przed commitem

- Publiczne pliki nie zawierają nazw ani flow spoza demo.
- Publiczne pliki nie opisują źródła modernizacji.
- Zdarzenia integracyjne w demo mają wyłącznie neutralne lub demo-własne nazwy.
- Przykłady w dokumentacji używają placeholderów typu `{Context}`, `{Aggregate}`, `{Event}`.
- Nie dodano klas, migracji ani testów z nazwami obcej domeny.
- Nie dodano endpointów ani scenariuszy produktowych spoza demo.
- Przed commitem uruchomiono wyszukiwanie podejrzanych słów w zmienionych publicznych plikach.
