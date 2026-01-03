<?php
/**
 * LHDN UBL Template
 * DO NOT change structure – only values are parameterized
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
 * MAIN INVOICE BUILDER
 * ======================================================= */
function lhdn_invoice_doc_ubl(string $invoiceNumber, array $params = []): array
{
    $sellerAddr = $params['seller_address'];
    $sellerCity  = $sellerAddr['city'];
    $sellerPost  = $sellerAddr['postcode'];
    $sellerState = $sellerAddr['state_code'];
    $sellerLine1 = $sellerAddr['line1'];
    $sellerLine1 = mb_substr($sellerAddr['line1'], 0, 150); // Truncate to maximum 150 characters
    $sellerCtry  = $sellerAddr['country'];

    $buyerTin   = $params['buyer_tin'];
    $buyerName  = $params['buyer_name'];
    $buyerPhone = $params['buyer_phone'];
    $buyerEmail = $params['buyer_email'];

    $buyerAddr  = $params['buyer_address'];
    $buyerCity  = $buyerAddr['city'];
    $buyerPost  = $buyerAddr['postcode'];
    $buyerState = $buyerAddr['state_code'];
    $buyerLine1 = mb_substr($buyerAddr['line1'], 0, 150); // Truncate to maximum 150 characters
    $buyerCtry  = $buyerAddr['country'];

    $total = $params['total'];
    $tax_amount = $params['tax_amount'] ?? 0;
    $subtotal = $total - $tax_amount;
    $tax_rate = $subtotal > 0 ? ($tax_amount / $subtotal) * 100 : 0;
    $taxCategoryId = $params['tax_category_id'] ?? 'E'; // Default to 'E' if not provided
    $industryClassificationCode = $params['industry_classification_code'] ?? '86909'; // Default MSIC code
    $industryClassificationName = LHDN_Helpers::get_msic_description($industryClassificationCode);

    $buyerIdScheme = 'NRIC'; // default
    if ($buyerTin === 'EI00000000020') {
      $buyerIdScheme = 'PASSPORT';
      $buyerIdValue = 'P12345678'; // ID is a must for normal invoice
      $ItemClassificationCode = '008'; // eCommerce
    }else if ($buyerTin === 'EI00000000010'){
      $buyerIdScheme = 'NRIC';
      $buyerIdValue = 'NA';  // ID is a must for consilidation invoice
      $ItemClassificationCode = '004'; // Consilidation Invoice
    }

    // TaxExemptionReason only when tax category is 'E'
    $taxExemptionReason = ($taxCategoryId === 'E') ? "Goods Exempted Under Malaysian Tax" : "";

    return [
        "_D" => "urn:oasis:names:specification:ubl:schema:xsd:Invoice-2",
        "_A" => "urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2",
        "_B" => "urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2",

        "Invoice" => [
            array_merge(
                [
                  "UBLExtensions" => lhdn_ubl_extensions_v1_1(),
                  "Signature" => lhdn_ubl_signature_v1_1(),
                ],
                [
                    "InvoiceTypeCode" => [[
                        "_" => "01",
                        "listVersionID" => defined('LHDN_UBL_VERSION') ? LHDN_UBL_VERSION : (class_exists('LHDN_MyInvoice_Plugin') ? LHDN_MyInvoice_Plugin::get_ubl_version() : '1.0')
                    ]],
                    "ID" => [["_" => $invoiceNumber]],
                    "IssueDate" => [["_" => gmdate("Y-m-d")]],
                    "IssueTime" => [["_" => gmdate("H:i:s") . "Z"]],
                    "DocumentCurrencyCode" => [["_" => "MYR"]],

                    "AccountingSupplierParty" => [[
                        "Party" => [[
                            "IndustryClassificationCode" => [[
                                "_" => $industryClassificationCode,
                                "name" => $industryClassificationName,
                            ]],
                            "PartyIdentification" => [
                                ["ID" => [["_" => LHDN_SELLER_TIN, "schemeID" => "TIN"]]],
                                ["ID" => [["_" => LHDN_SELLER_SST_NUMBER, "schemeID" => "SST"]]],
                                ["ID" => [["_" => LHDN_SELLER_TTX_NUMBER, "schemeID" => "TTX"]]],
                                ["ID" => [["_" => LHDN_SELLER_ID_VALUE, "schemeID" => LHDN_SELLER_ID_TYPE]]],
                            ],
                            "PostalAddress" => [[
                                "CityName" => [["_" => $sellerCity]],
                                "PostalZone" => [["_" => $sellerPost]],
                                "CountrySubentityCode" => [["_" => $sellerState]],
                                "AddressLine" => [["Line" => [["_" => $sellerLine1]]]],
                                "Country" => [[
                                    "IdentificationCode" => [[
                                        "_" => $sellerCtry,
                                        "listID" => "ISO3166-1",
                                        "listAgencyID" => "6",
                                    ]],
                                ]],
                            ]],
                            "PartyLegalEntity" => [[
                                "RegistrationName" => [["_" => LHDN_SELLER_NAME]],
                            ]],
                            "Contact" => [[
                                "Telephone" => [["_" => LHDN_SELLER_PHONE]],
                                "ElectronicMail" => [["_" => LHDN_SELLER_EMAIL]],
                            ]],
                        ]],
                    ]],

                    "AccountingCustomerParty" => [[
                        "Party" => [[
                            "PartyIdentification" => [
                                ["ID" => [["_" => $buyerTin, "schemeID" => "TIN"]]],
                                ["ID" => [["_" => $buyerIdValue, "schemeID" => $buyerIdScheme]]],
                                ["ID" => [["_" => "NA", "schemeID" => "SST"]]],
                                ["ID" => [["_" => "NA", "schemeID" => "TTX"]]],
                            ],
                            "PostalAddress" => [[
                                "CityName" => [["_" => $buyerCity]],
                                "PostalZone" => [["_" => $buyerPost]],
                                "CountrySubentityCode" => [["_" => $buyerState]],
                                "AddressLine" => [["Line" => [["_" => $buyerLine1]]]],
                                "Country" => [[
                                    "IdentificationCode" => [[
                                        "_" => $buyerCtry,
                                        "listID" => "ISO3166-1",
                                        "listAgencyID" => "6",
                                    ]],
                                ]],
                            ]],
                            "PartyLegalEntity" => [[
                                "RegistrationName" => [["_" => $buyerName]],
                            ]],
                            "Contact" => [[
                                "Telephone" => [["_" => $buyerPhone]],
                                "ElectronicMail" => [["_" => $buyerEmail]],
                            ]],
                        ]],
                    ]],

                    "InvoiceLine" => lhdn_build_invoice_lines($params['lines'], $ItemClassificationCode ?? '004', $taxCategoryId, $taxExemptionReason, $tax_amount),

                    "LegalMonetaryTotal" => [[
                        "LineExtensionAmount" => [["_" => $total, "currencyID" => "MYR"]],
                        "TaxExclusiveAmount" => [["_" => $subtotal, "currencyID" => "MYR"]],
                        "TaxInclusiveAmount" => [["_" => $total + $tax_amount, "currencyID" => "MYR"]],
                        "PayableAmount" => [["_" => $total, "currencyID" => "MYR"]],
                    ]],

                    
                    "TaxTotal" => [[
                        "TaxAmount" => [["_" => $tax_amount, "currencyID" => "MYR"]],
                        "TaxSubtotal" => [[
                            "TaxableAmount" => [["_" => $subtotal, "currencyID" => "MYR"]],
                            "TaxAmount" => [["_" => $tax_amount, "currencyID" => "MYR"]],
                            "TaxCategory" => [array_merge([
                                "ID" => [["_" => $taxCategoryId]],
                                "Percent" => [["_" => $tax_rate]],
                                "TaxScheme" => [[
                                    "ID" => [[
                                        "_" => "OTH",
                                        "schemeID" => "UN/ECE 5153",
                                        "schemeAgencyID" => "6",
                                    ]],
                                ]],
                            ], $taxExemptionReason ? ["TaxExemptionReason" => [["_" => $taxExemptionReason]]] : [])],
                        ]],
                    ]],
                ]
            ),
        ],
    ];
}

/* =========================================================
 * INVOICE LINE BUILDER
 * ======================================================= */
function lhdn_build_invoice_lines(array $lines, $ItemClassificationCode, $taxCategoryId = 'E', $taxExemptionReason = '', $lineTaxAmount = 0): array {
    $ublLines = [];

    foreach ($lines as $l) {
        $lineTotal = $l['qty'] * $l['unit_price'];
        // For exempt items (category 'E'), tax amount per line is 0
        $lineTax = ($taxCategoryId === 'E') ? 0 : $lineTaxAmount;

        $taxCategory = [
            "ID" => [["_" => $taxCategoryId]],
            "Percent" => [["_" => 0]],
            "TaxScheme" => [[
                "ID" => [[
                    "_" => "OTH",
                    "schemeID" => "UN/ECE 5153",
                    "schemeAgencyID" => "6",
                ]],
            ]],
        ];

        // Add TaxExemptionReason only if provided (when tax category is 'E')
        if ($taxExemptionReason) {
            $taxCategory["TaxExemptionReason"] = [["_" => $taxExemptionReason]];
        }

        $ublLines[] = [
            "ID" => [["_" => (string)$l['id']]],
            "InvoicedQuantity" => [["_" => $l['qty'], "unitCode" => "C62"]],
            "LineExtensionAmount" => [["_" => $lineTotal, "currencyID" => "MYR"]],

            "TaxTotal" => [[
                "TaxAmount" => [["_" => $lineTax, "currencyID" => "MYR"]],
                "TaxSubtotal" => [[
                    "TaxableAmount" => [["_" => $lineTotal, "currencyID" => "MYR"]],
                    "TaxAmount" => [["_" => $lineTax, "currencyID" => "MYR"]],
                    "TaxCategory" => [$taxCategory],
                ]],
            ]],

            "Item" => [[
                "CommodityClassification" => [[
                    "ItemClassificationCode" => [
                        ["_" => $ItemClassificationCode, "listID" => "CLASS"],
                    ],
                ]],
                "Description" => [["_" => $l['desc']]],
                "OriginCountry" => [[
                    "IdentificationCode" => [["_" => "MYS"]],
                ]],
            ]],

            "Price" => [[
                "PriceAmount" => [["_" => $l['unit_price'], "currencyID" => "MYR"]],
            ]],

            "ItemPriceExtension" => [[
                "Amount" => [["_" => $lineTotal, "currencyID" => "MYR"]],
            ]],
        ];
    }

    return $ublLines;
}

/* =========================================================
 * Signature
 * ======================================================= */
 function lhdn_ubl_signature_v1_1(): array
 {
     return [
         [
             "ID" => [
                 [
                     "_" => "urn:oasis:names:specification:ubl:signature:Invoice",
                 ],
             ],
             "SignatureMethod" => [
                 [
                     "_" => "urn:oasis:names:specification:ubl:dsig:enveloped:xades",
                 ],
             ],
         ],
     ];
 }

/* =========================================================
 * UBLExtension
 * ======================================================= */
 function lhdn_ubl_extensions_v1_1(): array
 {
     return [
         [
             "UBLExtension" => [
                 [
                     "ExtensionURI" => [
                         [
                             "_" => "urn:oasis:names:specification:ubl:dsig:enveloped:xades",
                         ],
                     ],
                     "ExtensionContent" => [
                         [
                             "UBLDocumentSignatures" => [
                                 [
                                     "SignatureInformation" => [
                                         [
                                             "ID" => [
                                                 [
                                                     "_" => "urn:oasis:names:specification:ubl:signature:1",
                                                 ],
                                             ],
                                             "ReferencedSignatureID" => [
                                                 [
                                                     "_" => "urn:oasis:names:specification:ubl:signature:Invoice",
                                                 ],
                                             ],
                                             "Signature" => [
                                                 [
                                                     "Id" => "signature",

                                                     "Object" => [
                                                         [
                                                             "QualifyingProperties" => [
                                                                 [
                                                                     "Target" => "signature",
                                                                     "SignedProperties" => [
                                                                         [
                                                                             "Id" => "id-xades-signed-props",
                                                                             "SignedSignatureProperties" => [
                                                                                 [
                                                                                     "SigningTime" => [
                                                                                         [
                                                                                             "_" => "",
                                                                                         ],
                                                                                     ],
                                                                                     "SigningCertificate" => [
                                                                                         [
                                                                                             "Cert" => [
                                                                                                 [
                                                                                                     "CertDigest" => [
                                                                                                         [
                                                                                                             "DigestMethod" => [
                                                                                                                 [
                                                                                                                     "_" => "",
                                                                                                                     "Algorithm" => "",
                                                                                                                 ],
                                                                                                             ],
                                                                                                             "DigestValue" => [
                                                                                                                 [
                                                                                                                     "_" => "",
                                                                                                                 ],
                                                                                                             ],
                                                                                                         ],
                                                                                                     ],
                                                                                                     "IssuerSerial" => [
                                                                                                         [
                                                                                                             "X509IssuerName" => [
                                                                                                                 [
                                                                                                                     "_" => "",
                                                                                                                 ],
                                                                                                             ],
                                                                                                             "X509SerialNumber" => [
                                                                                                                 [
                                                                                                                     "_" => "",
                                                                                                                 ],
                                                                                                             ],
                                                                                                         ],
                                                                                                     ],
                                                                                                 ],
                                                                                             ],
                                                                                         ],
                                                                                     ],
                                                                                 ],
                                                                             ],
                                                                         ],
                                                                     ],
                                                                 ],
                                                             ],
                                                         ],
                                                     ],

                                                     "KeyInfo" => [
                                                         [
                                                             "X509Data" => [
                                                                 [
                                                                     "X509Certificate" => [
                                                                         [
                                                                             "_" => "",
                                                                         ],
                                                                     ],
                                                                     "X509SubjectName" => [
                                                                         [
                                                                             "_" => "",
                                                                         ],
                                                                     ],
                                                                     "X509IssuerSerial" => [
                                                                         [
                                                                             "X509IssuerName" => [
                                                                                 [
                                                                                     "_" => "",
                                                                                 ],
                                                                             ],
                                                                             "X509SerialNumber" => [
                                                                                 [
                                                                                     "_" => "",
                                                                                 ],
                                                                             ],
                                                                         ],
                                                                     ],
                                                                 ],
                                                             ],
                                                         ],
                                                     ],

                                                     "SignatureValue" => [
                                                         [
                                                             "_" => "",
                                                         ],
                                                     ],

                                                     "SignedInfo" => [
                                                         [
                                                             "SignatureMethod" => [
                                                                 [
                                                                     "_" => "",
                                                                     "Algorithm" => "",
                                                                 ],
                                                             ],
                                                             "Reference" => [
                                                                 [
                                                                     "Type" => "",
                                                                     "URI" => "#id-xades-signed-props",
                                                                     "DigestMethod" => [
                                                                         [
                                                                             "_" => "",
                                                                             "Algorithm" => "",
                                                                         ],
                                                                     ],
                                                                     "DigestValue" => [
                                                                         [
                                                                             "_" => "",
                                                                         ],
                                                                     ],
                                                                 ],
                                                                 [
                                                                     "Type" => "",
                                                                     "URI" => "",
                                                                     "DigestMethod" => [
                                                                         [
                                                                             "_" => "",
                                                                             "Algorithm" => "",
                                                                         ],
                                                                     ],
                                                                     "DigestValue" => [
                                                                         [
                                                                             "_" => "",
                                                                         ],
                                                                     ],
                                                                 ],
                                                             ],
                                                         ],
                                                     ],
                                                 ],
                                             ],
                                         ],
                                     ],
                                 ],
                             ],
                         ],
                     ],
                 ],
             ],
         ],
     ];
 }

 /* =========================================================
  * PEM Signing
  * ======================================================= */
 function lhdn_sign_invoice_v1_1_with_pem(array $invoice): array
 {
     // === Load PEM (single PEM containing key + cert is OK) ===
     $pem = LHDN_PEM_PATH;
     if (!$pem) {
         throw new RuntimeException('Unable to load PEM');
     }

     $privateKey = openssl_pkey_get_private($pem);
     if (!$privateKey) {
         throw new RuntimeException('Private key not found in PEM');
     }

     if (!preg_match(
         '/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s',
         $pem,
         $m
     )) {
         throw new RuntimeException('Certificate not found in PEM');
     }

     $certificatePem = "-----BEGIN CERTIFICATE-----{$m[1]}-----END CERTIFICATE-----";
     $certInfo = openssl_x509_parse($certificatePem);

     $issuerName   = $certInfo['issuer']['CN'];
     $serialNumber = ltrim($certInfo['serialNumber'], '0');

     $certDer = base64_decode(
         preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s/', '', $certificatePem)
     );
     $certDigest = base64_encode(hash('sha256', $certDer, true));

     $signingTime = gmdate('Y-m-d\TH:i:s\Z');

     // ======================================================
     // 1. Build SignedProperties (XML ONLY)
     // ======================================================
     $xadesNS = 'http://uri.etsi.org/01903/v1.3.2#';
     $dsNS    = 'http://www.w3.org/2000/09/xmldsig#';

     $spDom = new DOMDocument('1.0', 'UTF-8');
     $spDom->preserveWhiteSpace = false;
     $spDom->formatOutput = false;

     $sp = $spDom->createElementNS($xadesNS, 'xades:SignedProperties');
     $sp->setAttribute('Id', 'id-xades-signed-props');
     $spDom->appendChild($sp);

     $ssp = $spDom->createElementNS($xadesNS, 'xades:SignedSignatureProperties');
     $sp->appendChild($ssp);

     $ssp->appendChild(
         $spDom->createElementNS($xadesNS, 'xades:SigningTime', $signingTime)
     );

     $sc = $spDom->createElementNS($xadesNS, 'xades:SigningCertificate');
     $ssp->appendChild($sc);

     $certEl = $spDom->createElementNS($xadesNS, 'xades:Cert');
     $sc->appendChild($certEl);

     $cd = $spDom->createElementNS($xadesNS, 'xades:CertDigest');
     $certEl->appendChild($cd);

     $dm = $spDom->createElementNS($dsNS, 'ds:DigestMethod');
     $dm->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
     $cd->appendChild($dm);

     $cd->appendChild(
         $spDom->createElementNS($dsNS, 'ds:DigestValue', $certDigest)
     );

     $is = $spDom->createElementNS($xadesNS, 'xades:IssuerSerial');
     $certEl->appendChild($is);

     $is->appendChild(
         $spDom->createElementNS($dsNS, 'ds:X509IssuerName', $issuerName)
     );
     $is->appendChild(
         $spDom->createElementNS($dsNS, 'ds:X509SerialNumber', $serialNumber)
     );

     // ======================================================
     // 2. Canonicalize SignedProperties + Digest (DS320 FIX)
     // ======================================================
     $signedPropsC14N = $spDom->C14N(false, false);
     $signedPropsDigest = base64_encode(
         hash('sha256', $signedPropsC14N, true)
     );

     // ======================================================
     // 3. Build SignedInfo
     // ======================================================
     $siDom = new DOMDocument('1.0', 'UTF-8');
     $siDom->preserveWhiteSpace = false;
     $siDom->formatOutput = false;

     $si = $siDom->createElementNS($dsNS, 'ds:SignedInfo');
     $siDom->appendChild($si);

     $cm = $siDom->createElementNS($dsNS, 'ds:CanonicalizationMethod');
     $cm->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
     $si->appendChild($cm);

     $sm = $siDom->createElementNS($dsNS, 'ds:SignatureMethod');
     $sm->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
     $si->appendChild($sm);

     $ref = $siDom->createElementNS($dsNS, 'ds:Reference');
     $ref->setAttribute('Type', 'http://uri.etsi.org/01903/v1.3.2#SignedProperties');
     $ref->setAttribute('URI', '#id-xades-signed-props');
     $si->appendChild($ref);

     $rdm = $siDom->createElementNS($dsNS, 'ds:DigestMethod');
     $rdm->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
     $ref->appendChild($rdm);

     $ref->appendChild(
         $siDom->createElementNS($dsNS, 'ds:DigestValue', $signedPropsDigest)
     );

     // ======================================================
     // 4. Sign SignedInfo
     // ======================================================
     $signedInfoC14N = $siDom->C14N(false, false);
     openssl_sign($signedInfoC14N, $sig, $privateKey, OPENSSL_ALGO_SHA256);
     $signatureValue = base64_encode($sig);

     // ======================================================
     // 5. Inject into UBL (SAMPLE-COMPLIANT)
     // ======================================================

     // Convert DOM → array
     $signedInfoArr = json_decode(
         json_encode(simplexml_import_dom($siDom)),
         true
     );

     $signedPropsArr = json_decode(
         json_encode(simplexml_import_dom($spDom)),
         true
     );

     // Extract base64 cert
     $certBase64 = preg_replace(
         '/-----(BEGIN|END) CERTIFICATE-----|\s/',
         '',
         $certificatePem
     );

     $invoice['Invoice'][0]['UBLExtensions'][0]['UBLExtension'][0]
     ['ExtensionContent'][0]['UBLDocumentSignatures'][0]
     ['SignatureInformation'][0]['Signature'][0] = [

         'Id' => 'signature',

         // MUST be array
         'SignedInfo' => [
             [
                 'CanonicalizationMethod' => [
                     [
                         'Algorithm' =>
                             'http://www.w3.org/TR/2001/REC-xml-c14n-20010315'
                     ]
                 ],
                 'SignatureMethod' => [
                     [
                         'Algorithm' =>
                             'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'
                     ]
                 ],
                 'Reference' => [
                     [
                         'Type' =>
                             'http://uri.etsi.org/01903/v1.3.2#SignedProperties',
                         'URI' => '#id-xades-signed-props',
                         'DigestMethod' => [
                             [
                                 'Algorithm' =>
                                     'http://www.w3.org/2001/04/xmlenc#sha256'
                             ]
                         ],
                         'DigestValue' => [
                             [
                                 '_' => $signedPropsDigest
                             ]
                         ]
                     ]
                 ]
             ]
         ],


         // MUST be array
         'SignatureValue' => [
             ['_' => $signatureValue]
         ],

         // REQUIRED by sample
         'KeyInfo' => [
             [
                 'X509Data' => [
                     [
                         'X509Certificate' => [
                             ['_' => $certBase64]
                         ]
                     ]
                 ]
             ]
         ],

         // Object → QualifyingProperties → SignedProperties
         'Object' => [
             [
                 'QualifyingProperties' => [
                     [
                         'Target' => 'signature',

                         // SignedProperties MUST be array
                         'SignedProperties' => [
                             $signedPropsArr
                         ]
                     ]
                 ]
             ]
         ]
     ];

     return $invoice;
 }
