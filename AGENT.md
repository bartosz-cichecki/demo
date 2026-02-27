# AGENT.md

Cel
- Ten plik jest instrukcją pracy dla agentów kodowania.
- Architektura i zasady systemu są wyłącznie w docs/architecture.md (source of truth).

## 1) Source of truth
Zanim cokolwiek zmienisz, przeczytaj:
- docs/architecture.md

Jeśli pojawia się konflikt: docs/architecture.md wygrywa.

## 2) Jak pracować w tym repo (workflow)
1) Zrozum zadanie
- Doprecyzuj: które route, które role/guardy, jakie inwarianty domenowe.
- Ustal minimalny diff (KISS).

2) Zlokalizuj miejsca zmiany
- Kod produkcyjny: app/src/
- Konfiguracja: app/config/
- Testy: app/tests/
- Dokumentacja: docs/

3) Implementuj
- Trzymaj się warstw i granic BC zgodnie z docs/architecture.md.
- Zmieniaj tylko to, co jest wymagane do DONE.
- Dodaj test, który udowadnia wymaganie (preferuj Behat dla zachowania HTTP, PHPUnit dla jednostek).

4) Uporządkuj
- Brak zbędnych refaktorów "przy okazji".
- Spójne nazewnictwo: route-name, komendy, klasy, pliki.

5) Odpal quality gates lokalnie (Makefile)
- make test
- make behat
- make phpstan
- make deptrac-ci

## 3) Twarde guardrails (nie negocjuj)
- Nie zmieniaj architektury ani publicznego API, jeśli prompt nie mówi inaczej.
- Nie przenoś logiki biznesowej do Application "bo szybciej".
- Nie loguj sekretów (tokeny/cookies tylko hash albo redakcja).
- Nie dodawaj nowych usług (Redis itp.), jeśli prompt tego nie wymaga.
- Minimalny diff, zero kosmetyki poza koniecznym.

## 4) Zasady orientacyjne (bez duplikowania architektury)
Szczegóły są w docs/architecture.md, ale pamiętaj:
- Domena trzyma reguły i inwarianty, Application orkiestruje przypadki użycia, Ui obsługuje HTTP.
- Read i write mogą mieć różne ścieżki (CQRS-lite), jeśli to jest już standardem w projekcie.
- Integracje i IO idą przez porty (Outside) zgodnie z docs/architecture.md.
- Subscriber/listener ma być cienki i przewidywalny.

## 5) Komendy (najczęściej używane)
- make test
- make behat
- make phpstan
- make deptrac-ci
- make cs-check (jeśli jest w repo)

Jeśli musisz użyć docker compose, rób to przez Makefile, o ile istnieje target.

## 6) Format odpowiedzi agenta po implementacji (raport)
W raporcie podaj:
- Summary (1-5 punktów)
- Files Created / Files Modified (lista)
- Jak sprawdziłeś (jakie make targety odpalone i wynik)
- Ryzyka/uwagi (krótko)
- Jeśli były zmiany w docs, wskaż które pliki i dlaczego

## 7) Szablon promptu (do wklejania dla agentów)
Skopiuj i uzupełnij:

Cel
- (1-2 zdania)

Kroki (konkret)
1) ...
2) ...

Komendy
- make test
- make behat
- make phpstan
- make deptrac-ci

Kryterium DONE
- (testy + zachowanie systemu)

Ograniczenia
- Bez refaktoru poza koniecznym
- Bez zmian w domenie, jeśli prompt nie mówi inaczej
- Bez zmian architektury/publicznego API

## 8) Commit message
- Conventional Commits: type(scope): opis
- Krótko, po angielsku
  Przykłady:
- feat(retro): add rate limiting for public endpoints
- fix(security): harden guard route map
- docs(runbooks): document rate limiter configuration
