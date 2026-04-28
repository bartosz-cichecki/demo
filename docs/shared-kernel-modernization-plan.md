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
   - Status: dodano `ClockInterface`, `SystemClock` i `MutableClock`; `DateTime` VO normalizuje czas do UTC; typ Doctrine odczytuje i zapisuje UTC storage string; PHP runtime ma `date.timezone=UTC`; Deptrac ma warstwę dla clocka; testy oraz quality gates przeszły.

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

4. Worker async outbox
   - dodać CLI worker,
   - obsłużyć claim batch, retry, ownership check i idempotency,
   - dodać neutralne testy dispatchu, błędu i ponownego przetwarzania.

5. Dokumentacja architektury
   - opisać UTC, clock, integration events, outbox i async consumption,
   - zachować neutralne nazwy i przykłady,
   - nie dokumentować pochodzenia zmian.

6. Docker i Composer baseline
   - potraktować pinning obrazów Docker jako osobny maintenance slice,
   - nie mieszać pinningu obrazów z wdrożeniem UTC,
   - aktualizować Composer tylko wtedy, gdy wynika to z potrzeb nowych mechanizmów albo quality gates.

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
