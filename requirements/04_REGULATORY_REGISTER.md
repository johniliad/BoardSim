# Regulation, guidance and evidence register

**Interpretation date:** 2026-11-12. **Scope:** verified top-level article topics and selected timings from official EUR-Lex/NIST; other linked sources are recorded as *supplied, not exhaustively checked*. This is an engineering input requiring counsel sign-off for deployment, NOT a substitute for official law or local legal advice. No secondary interpretation is used as an enforceable source.

## No legal points by association
A scenario can reference law without that law applying. Regulation scope (entity, sector, regulated role), event type, materiality/incident category, awareness timestamp, applicable date and reporting channel must be explicit. Keep `PENDING_CLASSIFICATION` rather than assume a violation. NIST/ENISA/FERMA material are not laws. **The scoring weights express BoardSim judgments, not statutory penalties.**

## Register and direct primary links

| Reference anchor | Source / section | Type | Applicability / trigger to test | Supported theme |
|---|---|---|---|---|
| <a id="reg-nis2-21"></a> `REG-NIS2-21` | [NIS2 Art. 21](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32022L2555) | LAW | `entity_in_scope AND national_transposition_applicable` | Risk-management measures and supply-chain, incident handling, business continuity, access control; essential/important entities subject to applicable transposition |
| <a id="reg-nis2-23"></a> `REG-NIS2-23` | [NIS2 Art. 23](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32022L2555) | LAW | `entity_in_scope AND significant_incident_confirmed AND awareness_time_known` | Significant-incident early warning within 24 h, notification within 72 h, final report one month after incident notification; check special providers/cases |
| <a id="reg-gdpr-32"></a> `REG-GDPR-32` | [GDPR Art. 32](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32016R0679) | LAW | `personal_data_processing AND role_confirmed` | Security of processing proportionate to risk; controller and processor circumstances |
| <a id="reg-gdpr-33"></a> `REG-GDPR-33` | [GDPR Art. 33](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32016R0679) | LAW | `controller AND personal_data_breach_confirmed AND risk_not_unlikely AND awareness_time_known` | Controller notifies supervisory authority without undue delay and where feasible within 72 h after awareness, unless unlikely risk to natural persons; phased information and processor-controller duties differ |
| <a id="reg-gdpr-34"></a> `REG-GDPR-34` | [GDPR Art. 34](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32016R0679) | LAW | `controller AND personal_data_breach_confirmed AND high_risk_to_individuals` | Communication to individuals when breach likely high risk, subject to statutory exceptions |
| <a id="reg-gdpr-22"></a> `REG-GDPR-22` | [GDPR Art. 22](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32016R0679) | LAW | `personal_data AND solely_automated_decision AND legal_or_similarly_significant_effect` | Rights and safeguards for certain solely automated decisions with legal or similarly significant effects; exceptions and conditions apply |
| <a id="reg-dora-5"></a> `REG-DORA-5` | [DORA Art. 5](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32022R2554) | LAW | `dora_financial_entity` | Governance and organization of ICT risk for financial entities within DORA scope |
| <a id="reg-dora-6"></a> `REG-DORA-6` | [DORA Art. 6](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32022R2554) | LAW | `dora_financial_entity` | ICT risk-management framework, policies and functions |
| <a id="reg-dora-19"></a> `REG-DORA-19` | [DORA Art. 19](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32022R2554) | LAW | `dora_financial_entity AND dora_major_ict_incident_classified` | Major ICT-related incident reporting subject to classification under Art. 18 and technical reporting instruments |
| <a id="reg-dora-28"></a> `REG-DORA-28` | [DORA Art. 28](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32022R2554) | LAW | `dora_financial_entity AND relevant_ict_third_party` | Management of ICT third-party risk as part of ICT risk governance, including critical arrangements |
| <a id="reg-dora-2024-1772"></a> `REG-DORA-2024-1772` | [Commission Delegated Regulation 2024/1772](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=OJ:L_202401772) | LAW | `dora_financial_entity AND classification_data_available` | DORA detailed criteria for major ICT incident classification; evaluate actual thresholds, not this simplified synopsis |
| <a id="reg-dora-2024-1773"></a> `REG-DORA-2024-1773` | [Commission Delegated Regulation 2024/1773](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=OJ:L_202401773) | LAW | `dora_financial_entity AND relevant_arrangement` | DORA ICT third-party services policy requirements for ICT services supporting critical or important functions |
| <a id="reg-dora-2025-301"></a> `REG-DORA-2025-301` | [Commission Delegated Regulation 2025/301 Art. 5](https://eur-lex.europa.eu/eli/reg_del/2025/301/oj/eng) | LAW | `dora_financial_entity AND dora_major_ict_incident_classified AND classification_timestamp_known` | Major ICT incident initial: within 4 h classification and (if classified in time) no later than 24 h from awareness; intermediate within 72 h of initial; final within one month of latest intermediate |
| <a id="reg-eu-2690"></a> `REG-EU-2690` | [Commission Implementing Regulation 2024/2690](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=OJ:L_202402690) | LAW | `listed_digital_provider_or_trust_service AND in_scope` | Detailed NIS2 technical/methodological measures and significance criteria for SPECIFIED digital infrastructure/service providers, not all NIS2 entities |
| <a id="reg-greece-5160"></a> `REG-GREECE-5160` | [Greek Law 5160/2024](https://cyber.gov.gr/wp-content/uploads/2024/12/ENOTHTA_5_%CE%9D%CE%9F%CE%9C%CE%9F%CE%98%CE%95%CE%A3%CE%99%CE%91_fek_a_195_2024-5160-2024-1.pdf) | NATIONAL_LAW | `greek_entity_and_scoped` | Greek transposition regime; verify consolidated text and current delegated acts before enabling enforceable local predicates |
| <a id="reg-cra-14"></a> `REG-CRA-14` | [Cyber Resilience Act Art. 14](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=OJ:L_202402847) | LAW | `manufacturer_scoped_product AND reportable_event AND date_at_least_2026_09_11` | Reporting of actively exploited vulnerabilities/severe incidents with phased start 2026-09-11; product/manufacturer scope must be verified |
| <a id="reg-aia-14"></a> `REG-AIA-14` | [AI Act Art. 14](https://eur-lex.europa.eu/legal-content/EN/TXT/PDF/?uri=CELEX:02024R1689-20260727) | LAW | `high_risk_ai_in_scope AND applicable_effective_date` | Human oversight requirements for in-scope high-risk AI systems, subject to phased dates and changes |
| <a id="reg-aia-26"></a> `REG-AIA-26` | [AI Act Art. 26](https://eur-lex.europa.eu/legal-content/EN/TXT/PDF/?uri=CELEX:02024R1689-20260727) | LAW | `deployer_of_high_risk_ai AND applicable_effective_date` | Deployers of high-risk AI systems: duties, human oversight, monitoring, logs and incident escalation where applicable |
| <a id="reg-aia-73"></a> `REG-AIA-73` | [AI Act Art. 73](https://eur-lex.europa.eu/legal-content/EN/TXT/PDF/?uri=CELEX:02024R1689-20260727) | LAW | `provider_high_risk_ai AND serious_incident_confirmed AND applicable_effective_date` | Serious-incident reporting by providers of high-risk AI; general 15-day cap and potentially shorter special triggers; verify effective date, scope, incident definition and exceptions |
| <a id="reg-nist-csf-gv"></a> `REG-NIST-CSF-GV` | [NIST CSF 2.0 GOVERN](https://nvlpubs.nist.gov/nistpubs/CSWP/NIST.CSWP.29.pdf) | FRAMEWORK | `guidance_not_law` | Governance, organizational context, risk strategy, policy, oversight and supply chain |
| <a id="reg-nist-csf-id"></a> `REG-NIST-CSF-ID` | [NIST CSF 2.0 IDENTIFY](https://nvlpubs.nist.gov/nistpubs/CSWP/NIST.CSWP.29.pdf) | FRAMEWORK | `guidance_not_law` | Asset, process and improvement understanding for prioritized risk management |
| <a id="reg-nist-csf-pr"></a> `REG-NIST-CSF-PR` | [NIST CSF 2.0 PROTECT](https://nvlpubs.nist.gov/nistpubs/CSWP/NIST.CSWP.29.pdf) | FRAMEWORK | `guidance_not_law` | Protective safeguards, access, training and platform/data security |
| <a id="reg-nist-csf-de"></a> `REG-NIST-CSF-DE` | [NIST CSF 2.0 DETECT](https://nvlpubs.nist.gov/nistpubs/CSWP/NIST.CSWP.29.pdf) | FRAMEWORK | `guidance_not_law` | Monitoring and adverse-event analysis |
| <a id="reg-nist-csf-rs"></a> `REG-NIST-CSF-RS` | [NIST CSF 2.0 RESPOND](https://nvlpubs.nist.gov/nistpubs/CSWP/NIST.CSWP.29.pdf) | FRAMEWORK | `guidance_not_law` | Incident analysis, communications, mitigation and coordination |
| <a id="reg-nist-csf-rc"></a> `REG-NIST-CSF-RC` | [NIST CSF 2.0 RECOVER](https://nvlpubs.nist.gov/nistpubs/CSWP/NIST.CSWP.29.pdf) | FRAMEWORK | `guidance_not_law` | Recovery planning, execution, lessons and stakeholder updates |
| <a id="reg-nist-800-61r3"></a> `REG-NIST-800-61R3` | [NIST SP 800-61 Rev. 3](https://nvlpubs.nist.gov/nistpubs/specialpublications/nist.sp.800-61r3.pdf) | GUIDANCE | `guidance_not_law` | Incident response recommendations aligned to CSF 2.0; helpful practice, not a notification statute |
| <a id="reg-enisa-map"></a> `REG-ENISA-MAP` | [ENISA 2025 mapping table v1.2](https://www.enisa.europa.eu/sites/default/files/2025-09/ENISA_Technical_Implementation_Guidance_Mapping_table_version_1.2.xlsx) | GUIDANCE | `guidance_not_law` | Mapping technical implementation guidance to selected NIS2 implementing requirements; use as interpretive aid after verifying workbook rows |
| <a id="reg-enisa-crisis"></a> `REG-ENISA-CRISIS` | [ENISA cyber crisis management study](https://www.enisa.europa.eu/sites/default/files/2024-11/ENISA%20Study%20Best%20Practices%20Cyber%20Crisis%20Management.pdf) | GUIDANCE | `guidance_not_law` | Best-practice crisis coordination and preparedness, not binding incident notification timing |

## Multi-regime incident reporting — mandatory separation

| Regime | First timing logic | Required gating |
|---|---|---|
| GDPR Art. 33 | Controller: without undue delay and, where feasible, within **72 h from awareness**; exemption for unlikely risk to rights/freedoms; delayed notice reasons | Personal data breach, controller/processor role, awareness, harm-risk classification |
| NIS2 Art. 23 | For significant incidents of covered entities: early warning **within 24 h** and incident notification **within 72 h** from awareness; final report **not later than one month after incident notification** | National scope/transposition; significant-incident criteria; sector or provider-specific rules |
| DORA Art. 19 + delegated 2025/301 Art. 5 | Major ICT: initial within **4 h of major classification** and usually no later than **24 h of incident awareness** when classified in that window; intermediate by **72 h from initial**; final within **one month after intermediate/latest update**; later major classification has special rule | DORA financial entity; major incident thresholds under 2024/1772; separate awareness/classification timestamps |
| AI Act Art. 73 | Provider high-risk AI serious incident: general **15-day** outside cap after awareness with more urgent events and different shorter conditions; assess consolidated text and effective dates | Provider/deployer role, high-risk scope, serious incident definition, date of applicability; cannot infer from an ordinary model error |
| CRA Art. 14 | Reporting obligations begin **2026-09-11**; full main regulation from **2027-12-11** | Manufacturer/product scope and reportable vulnerability/severe incident; don't apply to every enterprise outage |

**2026 legal-change caution:** User provided the **27 July 2026 consolidated AI Act** link; it supersedes simplistic older timetable assumptions for this specification. Revalidate before freezing any AI Act Article 73 implementation. The reporting classifications and deadlines above are summaries; the original article prevails.

## Other user-supplied sources and verification status

| Source | Status / treatment |
|---|---|
| Greek NIS2 incident notification guide, March 2025 | Official cyber.gov.gr URL supplied; local procedural guide, verify revisions and workflow against binding law before hardcoding. |
| Greek ministerial decisions / Gazette files dated 2025 and Greek vulnerability guidance Sep 2026 | Official cyber.gov.gr URLs supplied; exact article-level rules and current consolidation **NOT VERIFIED in this package**. Do not turn into executable legal predicates until reviewed. |
| EUR-Lex OJ:C_202503445 and Commission Recommendation 2017/1584 | Official references supplied; specific legal effect and relevant articles **NOT VERIFIED** here. Track but do not enforce. |
| FERMA Cyber Reporting Stack 2024 | Secondary professional association commentary; **context only**. Not an authoritative legal rule. |
| ENISA 2025 mapping workbook / 2024 crisis study | Official agency guidance; individual spreadsheet row mappings not verified in this package; cannot substitute for legal text. |

### Direct links for additional supplied materials
- [Greek incident guide](https://cyber.gov.gr/wp-content/uploads/2025/03/250323_%CE%9F%CE%B4%CE%B7%CE%B3%CF%8C%CF%82-%CE%91%CE%BD%CE%B1%CF%86%CE%BF%CF%81%CE%AC%CF%82-%CE%9D.-5160_2024-1.pdf)
- [Greek vulnerability document 2026](https://cyber.gov.gr/wp-content/uploads/2026/09/%CE%94%CE%B9%CE%B1%CF%87%CE%B5%CE%AF%CF%81%CE%B9%CF%83%CE%B7-%CE%B5%CF%85%CF%80%CE%B1%CE%B8%CE%B5%CE%B9%CF%8E%CE%BD-%CE%B1%CF%80%CF%8C-%CF%84%CE%B9%CF%82-%CE%B2%CE%B1%CF%83%CE%B9%CE%BA%CE%AD%CF%82-%CE%BF%CE%BD%CF%84%CF%8C%CF%84%CE%B7%CF%84%CE%B5%CF%82-%CF%84%CE%BF%CF%85-%CE%BD.-5160-2024.pdf)
- [Greek ministerial PDF 1899](https://cyber.gov.gr/wp-content/uploads/2025/08/%CE%91%CE%A0%CE%9F%CE%A6%CE%91%CE%A3%CE%97_%CE%A5%CE%A0%CE%9F%CE%A5%CE%A1%CE%93%CE%9F%CE%A5_1899_20250204250.pdf)
- [Greek gazette B-4241](https://cyber.gov.gr/wp-content/uploads/2025/08/%CE%92-4241.pdf)
- [Greek gazette May 2025](https://cyber.gov.gr/wp-content/uploads/2025/05/20250202186.pdf)
- [Commission Official Journal OJ:C_202503445](https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=OJ:C_202503445)
- [Commission Recommendation 2017/1584](https://eur-lex.europa.eu/legal-content/EN/TXT/PDF/?uri=CELEX:32017H1584)
- [FERMA report, nonbinding secondary](https://ferma.eu/wp-content/uploads/2024/10/CYBER-REPORTING-STACK-NAVIGATING-THE-EU-REQUIREMENTS-web-1.pdf)

## Regulatory rule-resolution decision tree
1. Is the legal instrument applicable on the simulation date? If `false`, `NOT_APPLICABLE`; if unclear, `REQUIRES_HUMAN_REVIEW`.
2. Is the fictional entity/role/service in scope? If unclear, `PENDING_CLASSIFICATION`.
3. Do observed facts meet the specific incident and materiality conditions? If unclear, `PENDING_CLASSIFICATION` (no legal breach designation).
4. Record legally relevant awareness and classification timestamps separately; derive deadlines by instrument **only** if triggered.
5. Store result, controlling text link, regulation version, assessor/reviewer and machine-readable evidence IDs. National procedural requirements may supersede generic workflows; verify for the chosen country.
6. Player response can be evaluated for timeliness and diligence; legal breach claims require facts plus scope plus missed real deadline. Never equate an internal assessment due date to a statutory deadline.
