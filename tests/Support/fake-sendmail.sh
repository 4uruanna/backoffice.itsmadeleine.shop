#!/bin/sh
# Faux sendmail pour tester mail() : enregistre les arguments puis le message reçu sur l'entrée standard.
# Fichier de sortie : variable FAKE_SENDMAIL_OUT. FAKE_SENDMAIL_FAIL=1 : simule un refus.
{
    echo "ARGS: $*"
    cat
} > "$FAKE_SENDMAIL_OUT"
[ "${FAKE_SENDMAIL_FAIL:-0}" = "1" ] && exit 1
exit 0
