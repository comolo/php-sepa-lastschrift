<?php

/*
 * Copyright (c) 2013, Michael Braun <michael-dev@fami-braun.de>.
 * All rights reserved.
 *
 * Modified by Hendrik Obermayer, Comolo GmbH, 2015
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301  USA
 *
 *
 */

global $sepaLastschriftXMLVersion; # 008.002.02
global $sepaLastschriftXSD; # ../media/

/**
 * Class SepaDirectDebit
 * Implementing SPEA pain.008.003.02
 *
 */
class SepaDirectDebit
{
    private $date; /* Y-m-d\TH:i:s\Z */
    private $msgid; /* ([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|'| ]){1,35} */
    private $txs = array(); /* $type => Array */
    private $initator = "missing";
    private $sum = array(); /* $type => cents */
    private $creditorName = "missing";
    private $creditorIBAN = "missing";
    private $creditorBIC = "missing";
    private $creditorID = "missing"; /* Gläubiger-ID */
    private $ccy = "EUR"; /* EUR */
    private $txTypes = array("FRST", "RCUR", "OOFF", "FNAL");
    private $cdtype = "CORE";

    /**
     * Create new SepaDirectDebit Object
     *
     * @param DateTime $date
     * @param $msgid ist ein Prefix, welches auch für sie Sammler (PmtInfId) als Prefix verwendet wird. Daher die Länge auf 29 beschränken!
     * @param $initiator
     * @param $creditorName
     * @param $creditorIBAN
     * @param $creditorBIC
     * @param $creditorID
     * @param string $ccy
     * @param string $cdtype
     */
    public function __construct(DateTime $date, $msgid, $initiator, $creditorName, $creditorIBAN, $creditorBIC, $creditorID, $ccy = "EUR", $cdtype = "CORE" /* B2B or COR1 */)
    {
        $this->date = $date;
        $this->msgid = $msgid;
        $this->initiator = $initiator;
        $this->creditorName = $creditorName;
        $this->creditorIBAN = $creditorIBAN;
        $this->creditorBIC = $creditorBIC;
        $this->creditorID = $creditorID;
        $this->ccy = $ccy;
        $this->cdtype = $cdtype;

        if (!preg_match("#^([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|'| ]){1,29}$#", $msgid)) {
            die("ungültige msgid");
        }
    }

    /**
     * Lastschrift einfügen.
     * @param name Kontoinhaber
     * @param amount maximal 2 Bruchziffern, float
     * @param mandateSignatureDate Datum der Unterschrift auf dem Mandat; als class DateTime Object
     * @param sepatyp FRST RCUR OOFF FNAL
     * @param UltmtDbtr Name des Schuldners (nur Information; wenn abweichend von Kontoinhaber) (optional)
     */
    public function addDebit($id, $iban, $bic, $name, $mandate, DateTime $mandateSignatureDate, $amount /* float */, $subject, $type, $UltmtDbtr = null)
    {
        if (!preg_match("#^([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|'| ]){1,35}$#", $id)) {
            die("invalid id $id");
        }
        if (!preg_match("#^([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|']){1,35}$#", $mandate)) {
            die("invalid mandate $mandate");
        }
        if (strlen($subject) < 1 or strlen($subject) > 140) {
            die("invalid subject length");
        }
        # Sparkasse Arnstadt-Ilmenau erlaubt hier nur a-zA-Z0-9 sowie -':?,+()/. und Leerzeichen
        if (!preg_match("#^[A-Za-z0-9\+\?/\-:\(\)\.,' ]{1,140}$#", $subject)) {
            die("invalid subject $subject: #^[A-Za-z0-9\+\?/\-:\(\)\.,' ]{1,140}$#");
        }
        if (strlen($name) < 1 or strlen($name) > 70) {
            die("invalid name length");
        }
        # Sparkasse Arnstadt-Ilmenau erlaubt hier nur a-zA-Z0-9 sowie -':?,+()/. und Leerzeichen
        if (!preg_match("#^[A-Za-z0-9\+\?/\-:\(\)\.,' ]{1,70}$#", $name)) {
            die("invalid name $name: #^[A-Za-z0-9\+\?/\-:\(\)\.,' ]{1,70}$#");
        }
        if ($UltmtDbtr !== NULL && (strlen($UltmtDbtr) < 1 or strlen($UltmtDbtr) > 70)) {
            die("invalid UltmtDbtr length");
        }
        if ($amount < 0.01 || $amount > 999999999.99) {
            die("invalid amount $amount");
        }
        $amount = round($amount * 100);
        if (!in_array($type, $this->txTypes)) {
            die("invalid type $type");
        }
        $tx = array("id" => $id, "IBAN" => $iban, "BIC" => $bic, "name" => $name, "mandate" => $mandate, "mandateSignatureDate" => $mandateSignatureDate, "amount" => $amount, "subject" => $subject);
        if ($UltmtDbtr !== NULL) {
            $ts["UltmtDbtr"] = $UltmtDbtr;
        }
        if (!isset($this->txs[$type])) {
            $this->txs[$type] = array();
        }
        $this->txs[$type][] = $tx;
        if (!isset($this->sum[$type])) {
            $this->sum[$type] = 0;
        }
        $this->sum[$type] += $amount;
    }

    /**
     * Return Sepa Direct Debit Entries as XML
     *
     * @return bool|string
     */
    public function asXML()
    {
        global $sepaLastschriftXMLVersion; # 008.002.02
        global $sepaLastschriftXSD; # ../media/

        /** output */
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');

        $painVersion = "008.002.02";
        if (isset($sepaLastschriftXMLVersion)) {
            $painVersion = $sepaLastschriftXMLVersion;
        }
        $painXSDFile = "pain." . $painVersion . ".xsd";

        $xml->startElement('Document');
        $xml->writeAttribute('xmlns', 'urn:iso:std:iso:20022:tech:xsd:pain.' . $painVersion);
        $xml->writeAttributeNS('xsi', 'schemaLocation', 'http://www.w3.org/2001/XMLSchema-instance', 'urn:iso:std:iso:20022:tech:xsd:pain.' . $painVersion . ' ' . $painXSDFile);
        $xml->startElement('CstmrDrctDbtInitn');
        $this->addGrpHdr($xml);
        foreach ($this->txs as $type => $txs) {
            $this->addPmtInf($xml, $txs, $this->sum[$type], $type);
        }
        $xml->endElement(); /* CstmrDrctDbtInitn */
        $xml->endElement(); /* Document */

        $xml->endDocument();
        $xmlString = $xml->outputMemory(true);

        // verify xml
        if (isset($sepaLastschriftXSD)) {
            $xsdFile = $sepaLastschriftXSD . "/" . $painXSDFile;
            if (!is_file($xsdFile)) {
                add_message("Die Schema-Datei $xsdFile wurde nicht gefunden.");

                return false;
            } else {
                $tempDom = new DOMDocument();
                $tempDom->loadXML($xmlString);
                if (!$tempDom->schemaValidate($xsdFile)) {
                    add_message("Die erzeugten Daten sind ungültig.");

                    return false;
                }
            }
        }

        return $xmlString;
    }

    protected function addGrpHdr(XMLWriter $xml)
    {
        $xml->startElement('GrpHdr');
        $xml->writeElement('MsgId', $this->msgid);
        $dt = new DateTime("now", new DateTimeZone('Etc/UTC'));
        $xml->writeElement('CreDtTm', $dt->format('Y-m-d\TH:i:s\Z'));
        $ctn = 0;
        foreach ($this->txs as $v) {
            $ctn += count($v);
        }
        $xml->writeElement('NbOfTxs', $ctn);
        $sum = 0;
        foreach ($this->sum as $v) {
            $sum += $v;
        }
        $xml->writeElement('CtrlSum', $this->formatCcy($sum));
        $xml->startElement('InitgPty');
        $xml->writeElement('Nm', $this->initiator);
        $xml->endElement(); /* InitgPty */
        $xml->endElement(); /* GrpHdr */
    }

    protected static function formatCcy($cents)
    {
        return sprintf("%d.%02d", ($cents / 100), ($cents % 100));
    }

    /** $type: FRST; RCUR; OOFF; FNAL */
    protected function addPmtInf(XMLWriter $xml, $txs, $sum, $type)
    {
        if (!preg_match("#^([A-Za-z0-9]|[\+|\?|/|\-|:|\(|\)|\.|,|'| ]){1,5}$#", $type)) {
            die("invalid type $type");
        }

        $xml->startElement('PmtInf');
        $xml->writeElement('PmtInfId', $this->msgid . '-' . $type);
        $xml->writeElement('PmtMtd', 'DD');
        $xml->writeElement('NbOfTxs', count($txs));
        $xml->writeElement('CtrlSum', $this->formatCcy($sum));
        $xml->startElement('PmtTpInf');
        $xml->startElement('SvcLvl');
        $xml->writeElement('Cd', 'SEPA');
        $xml->endElement(); /* SvcLvl */
        $xml->startElement('LclInstrm');
        $xml->writeElement('Cd', $this->cdtype);
        $xml->endElement(); /* LclInstrm */
        $xml->writeElement('SeqTp', $type);
        $xml->endElement(); /* PmtTpInf */
        $xml->writeElement('ReqdColltnDt', $this->date->format('Y-m-d'));
        $xml->startElement('Cdtr');
        $xml->writeElement('Nm', $this->creditorName);
        $xml->endElement(); /* Cdtr */
        $xml->startElement('CdtrAcct');
        $xml->startElement('Id');
        $xml->writeElement('IBAN', $this->creditorIBAN);
        $xml->endElement(); /* Id */
        $xml->endElement(); /* CdtrAcct */
        $xml->startElement('CdtrAgt');
        $xml->startElement('FinInstnId');
        $xml->writeElement('BIC', $this->creditorBIC);
        $xml->endElement(); /* FinInstnId */
        $xml->endElement(); /* CdtrAgt */
        $xml->writeElement('ChrgBr', 'SLEV');
        $xml->startElement('CdtrSchmeId');
        $xml->startElement('Id');
        $xml->startElement('PrvtId');
        $xml->startElement('Othr');
        $xml->writeElement('Id', $this->creditorID);
        $xml->startElement('SchmeNm');
        $xml->writeElement('Prtry', 'SEPA');
        $xml->endElement(); /* SchmeNm */
        $xml->endElement(); /* Othr */
        $xml->endElement(); /* PrvtId */
        $xml->endElement(); /* Id */
        $xml->endElement(); /* CdtrSchmeId */
        foreach ($txs as $tx) {
            $this->addTX($xml, $tx);
        }
        $xml->endElement(); /* PmtInf */
    }

    /**
     * @param XMLWriter $xml
     * @param $tx
     */
    protected function addTX(XMLWriter $xml, $tx)
    {
        $xml->startElement('DrctDbtTxInf');
        $xml->startElement('PmtId');
        $xml->writeElement('EndToEndId', $tx["id"]);
        $xml->endElement(); /* PmtId */
        $xml->startElement('InstdAmt');
        $xml->writeAttribute("Ccy", $this->ccy);
        $xml->text($this->formatCcy($tx["amount"]));
        $xml->endElement(); /* InstdAmt */
        $xml->startElement('DrctDbtTx');
        $xml->startElement('MndtRltdInf');
        $xml->writeElement('MndtId', $tx["mandate"]);
        $xml->writeElement('DtOfSgntr', $tx["mandateSignatureDate"]->format('Y-m-d'));
        /* No Amendment aka Change-Tracing supported! */
        $xml->endElement(); /* MndtRltdInf */
        $xml->endElement(); /* DrctDbtTx */
        $xml->startElement('DbtrAgt');
        $xml->startElement('FinInstnId');
        $xml->writeElement('BIC', $tx["BIC"]);
        $xml->endElement(); /* FinInstnId */
        $xml->endElement(); /* DbtrAgt */
        $xml->startElement('Dbtr');
        $xml->writeElement('Nm', $tx["name"]);
        $xml->endElement(); /* Dbtr */
        $xml->startElement('DbtrAcct');
        $xml->startElement('Id');
        $xml->writeElement('IBAN', $tx["IBAN"]);
        $xml->endElement(); /* Id */
        $xml->endElement(); /* DbtrAcct */

        if (isset($tx["UltmtDbtr"])) {
            $xml->startElement('UltmtDbtr');
            $xml->writeElement('Nm', $tx["UltmtDbtr"]); /* Zahlungspflichtiger, falls vom Kontoinhaber abweichend */
            $xml->endElement(); /* UltmtDbtr */
        }

        $xml->startElement('RmtInf');
        $xml->writeElement('Ustrd', $tx["subject"]);
        $xml->endElement(); /* RmtInf */
        $xml->endElement(); /* DrctDbtTxInf */
    }
}
