#!/bin/bash
for i in $(seq 1 20); do
  sleep 30
  companies=$(php bin/console dbal:run-sql "SELECT COUNT(*) FROM companies" --env=dev 2>&1 | tail -1)
  contacts=$(php bin/console dbal:run-sql "SELECT COUNT(*) FROM contacts" --env=dev 2>&1 | tail -1)
  pid1=$(kill -0 94531 2>/dev/null && echo "running" || echo "STOPPED")
  pid2=$(kill -0 99227 2>/dev/null && echo "running" || echo "STOPPED")
  echo "[$(date '+%H:%M:%S')] Poll $i | Companies: $companies | Contacts: $contacts | T1($pid1) T2($pid2)"
done
