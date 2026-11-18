// This file is part of Moodle and is licensed under the GNU GPL v3 or later.

import {useCallback, useEffect, useMemo, useState} from "react";
import {mcClasses, useModernCommerceClassSync} from "./design_system";

declare const M: {cfg: {sesskey: string; wwwroot: string}};

type Labels = Record<string, string>;
type Methods = Record<string, string>;
type Company = {
    id: number; name: string; status: string; domains?: string; capacity: number;
    used: number; members?: number; graceuntil: number;
};
type Domain = {id: number; domain: string; status: string};
type License = {id: number; seatcount: number; status: string; timestart: number; timeend: number; orderid: number};
type Member = {
    id: number; userid: number; name: string; email: string; companyrole: string;
    seatstate: string; islearner: number; lastaccess: number;
};
type Course = {
    courseid: number; fullname: string; shortname: string; accessmode: string;
    enrolled?: number; timeend: number; url?: string;
};
type Audit = {id: number; action: string; objecttype: string; objectid: number; actoruserid: number; timecreated: number};
type Offer = {
    id: number; productid: number; name: string; productname: string; producttype: string; sku: string;
    durationdays: number; minseats: number; maxseats: number; status: string;
    unitprice: number; currency: string; courses: Course[];
};
type CompanyDetail = {company: Company; domains: Domain[]; licenses: License[]; members: Member[]; courses: Course[]; audit: Audit[]};
type LearningData = {
    hascompany: number;
    company?: Company & {companyrole: string; seatstate: string; memberid: number; canmanage: number};
    courses: Course[];
};

type Props = {
    mode: "admin" | "manage" | "learning" | "checkout";
    methods: Methods;
    labels: Labels;
    companyId?: number;
    canAdmin?: boolean;
    manageUrl?: string;
};

const callService = async <T, >(method: string, args: unknown = {}): Promise<T> => {
    const response = await fetch(
        `${M.cfg.wwwroot}/lib/ajax/service.php?sesskey=${encodeURIComponent(M.cfg.sesskey)}&info=${encodeURIComponent(method)}`,
        {
            method: "POST",
            credentials: "same-origin",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify([{index: 0, methodname: method, args}]),
        },
    );
    if (!response.ok) {
        throw new Error(`${response.status} ${response.statusText}`);
    }
    const payload = await response.json();
    const first = Array.isArray(payload) ? payload[0] : payload;
    if (first?.error) {
        throw new Error(first.exception?.message ?? first.message ?? "Service request failed");
    }
    return (first?.data ?? first) as T;
};

const dateLabel = (timestamp: number): string => timestamp > 0
    ? new Intl.DateTimeFormat(document.documentElement.lang || undefined, {dateStyle: "medium"}).format(timestamp * 1000)
    : "—";

const money = (amount: number, currency: string): string => new Intl.NumberFormat(
    document.documentElement.lang || undefined,
    {style: "currency", currency: currency || "USD"},
).format(amount);

const Notice = ({error, success}: {error: string; success: string}) => (
    <>
        {error && <div className="alert alert-danger" role="alert">{error}</div>}
        {success && <div className="alert alert-success" role="status">{success}</div>}
    </>
);

function AdminView({methods, labels, manageUrl}: Props) {
    const [companies, setCompanies] = useState<Company[]>([]);
    const [offers, setOffers] = useState<Offer[]>([]);
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const [search, setSearch] = useState("");
    const [companyForm, setCompanyForm] = useState({name: "", domain: "", ownerid: ""});
    const [offerForm, setOfferForm] = useState({
        id: "0", productid: "", name: "", durationdays: "365", minseats: "1", maxseats: "", courses: "",
    });

    const load = useCallback(async () => {
        try {
            setError("");
            const [companyData, offerData] = await Promise.all([
                callService<{companies: Company[]}>(methods.listCompanies, {search}),
                callService<{offers: Offer[]}>(methods.listOffers, {}),
            ]);
            setCompanies(companyData.companies);
            setOffers(offerData.offers);
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : labels.errorgeneric);
        }
    }, [labels.errorgeneric, methods.listCompanies, methods.listOffers, search]);

    useEffect(() => { void load(); }, [load]);

    const createCompany = async (event: React.FormEvent) => {
        event.preventDefault();
        try {
            setError("");
            const result = await callService<{message: string}>(methods.saveCompany, {
                name: companyForm.name,
                domain: companyForm.domain,
                ownerid: Number(companyForm.ownerid) || 0,
            });
            setSuccess(result.message);
            setCompanyForm({name: "", domain: "", ownerid: ""});
            await load();
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : labels.errorgeneric);
        }
    };

    const saveOffer = async (event: React.FormEvent) => {
        event.preventDefault();
        const courses = offerForm.courses.split(",").map((entry) => {
            const [courseid, mode] = entry.trim().split(":");
            return {courseid: Number(courseid), accessmode: mode === "optional" ? "optional" : "mandatory"};
        }).filter((course) => course.courseid > 0);
        try {
            setError("");
            const result = await callService<{message: string}>(methods.saveOffer, {
                offer: {
                    id: Number(offerForm.id) || 0,
                    productid: Number(offerForm.productid),
                    name: offerForm.name,
                    durationdays: Number(offerForm.durationdays) || 365,
                    minseats: Number(offerForm.minseats) || 1,
                    maxseats: Number(offerForm.maxseats) || 0,
                    status: "active",
                },
                courses,
            });
            setSuccess(result.message);
            setOfferForm({id: "0", productid: "", name: "", durationdays: "365", minseats: "1", maxseats: "", courses: ""});
            await load();
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : labels.errorgeneric);
        }
    };

    return (
        <div className={mcClasses("mc-stack mc-stack--lg")}>
            <Notice error={error} success={success} />
            <section className={mcClasses("mc-card")}>
                <div className={mcClasses("mc-card-body")}>
                    <div className="d-flex gap-2 align-items-end flex-wrap">
                        <label className="flex-grow-1">
                            <span className="form-label">{labels.searchcompanies}</span>
                            <input className="form-control" value={search} onChange={(event) => setSearch(event.target.value)} />
                        </label>
                        <button className={mcClasses("mc-button btn-mc-secondary")} type="button" onClick={() => void load()}>
                            {labels.refresh}
                        </button>
                    </div>
                </div>
                <div className="table-responsive">
                    <table className="table mb-0">
                        <thead><tr><th>{labels.company}</th><th>{labels.domains}</th><th>{labels.seats}</th><th>{labels.members}</th><th>{labels.status}</th><th>{labels.actions}</th></tr></thead>
                        <tbody>{companies.map((company) => (
                            <tr key={company.id}>
                                <td>{company.name}</td><td>{company.domains || "—"}</td>
                                <td>{company.used} / {company.capacity}</td><td>{company.members ?? 0}</td><td>{company.status}</td>
                                <td><a href={`${manageUrl}?companyid=${company.id}`}>{labels.manage}</a></td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div>
            </section>
            <div className="row g-4">
                <div className="col-lg-6">
                    <section className={mcClasses("mc-card h-100")}><div className={mcClasses("mc-card-body")}>
                        <h3>{labels.createcompany}</h3>
                        <form className="d-grid gap-3" onSubmit={createCompany}>
                            <label><span className="form-label">{labels.companyname}</span><input required className="form-control" value={companyForm.name} onChange={(event) => setCompanyForm({...companyForm, name: event.target.value})} /></label>
                            <label><span className="form-label">{labels.domain}</span><input required className="form-control" placeholder="example.com" value={companyForm.domain} onChange={(event) => setCompanyForm({...companyForm, domain: event.target.value})} /></label>
                            <label><span className="form-label">{labels.owneruserid}</span><input className="form-control" inputMode="numeric" value={companyForm.ownerid} onChange={(event) => setCompanyForm({...companyForm, ownerid: event.target.value})} /></label>
                            <button className={mcClasses("mc-button btn-mc-primary")} type="submit">{labels.create}</button>
                        </form>
                    </div></section>
                </div>
                <div className="col-lg-6">
                    <section className={mcClasses("mc-card h-100")}><div className={mcClasses("mc-card-body")}>
                        <h3>{Number(offerForm.id) > 0 ? labels.edit : labels.createoffer}</h3>
                        <form className="d-grid gap-3" onSubmit={saveOffer}>
                            <label><span className="form-label">{labels.productid}</span><input required className="form-control" inputMode="numeric" value={offerForm.productid} onChange={(event) => setOfferForm({...offerForm, productid: event.target.value})} /></label>
                            <label><span className="form-label">{labels.offername}</span><input required className="form-control" value={offerForm.name} onChange={(event) => setOfferForm({...offerForm, name: event.target.value})} /></label>
                            <div className="row g-3"><label className="col"><span className="form-label">{labels.durationdays}</span><input className="form-control" inputMode="numeric" value={offerForm.durationdays} onChange={(event) => setOfferForm({...offerForm, durationdays: event.target.value})} /></label><label className="col"><span className="form-label">{labels.minseats}</span><input className="form-control" inputMode="numeric" value={offerForm.minseats} onChange={(event) => setOfferForm({...offerForm, minseats: event.target.value})} /></label><label className="col"><span className="form-label">{labels.maxseats}</span><input className="form-control" inputMode="numeric" value={offerForm.maxseats} onChange={(event) => setOfferForm({...offerForm, maxseats: event.target.value})} /></label></div>
                            <label><span className="form-label">{labels.coursemapping}</span><input required className="form-control" placeholder="12:mandatory, 18:optional" value={offerForm.courses} onChange={(event) => setOfferForm({...offerForm, courses: event.target.value})} /><small className="form-text">{labels.coursemappinghelp}</small></label>
                            <button className={mcClasses("mc-button btn-mc-primary")} type="submit">{labels.save}</button>
                        </form>
                    </div></section>
                </div>
            </div>
            <section className={mcClasses("mc-card")}><div className={mcClasses("mc-card-body")}><h3>{labels.licenseoffers}</h3></div>
                <div className="table-responsive"><table className="table mb-0"><thead><tr><th>{labels.offer}</th><th>{labels.product}</th><th>{labels.duration}</th><th>{labels.seats}</th><th>{labels.courses}</th><th>{labels.status}</th><th>{labels.actions}</th></tr></thead><tbody>
                    {offers.map((offer) => <tr key={offer.id}><td>{offer.name}</td><td>{offer.productname}</td><td>{offer.durationdays} {labels.days}</td><td>{offer.minseats}–{offer.maxseats || "∞"}</td><td>{offer.courses.length}</td><td>{offer.status}</td><td><button type="button" className={mcClasses("mc-button btn-mc-secondary py-1 px-2")} onClick={() => setOfferForm({id: String(offer.id), productid: String(offer.productid), name: offer.name, durationdays: String(offer.durationdays), minseats: String(offer.minseats), maxseats: offer.maxseats ? String(offer.maxseats) : "", courses: offer.courses.map((course) => `${course.courseid}:${course.accessmode}`).join(", ")})}>{labels.edit}</button></td></tr>)}
                </tbody></table></div>
            </section>
        </div>
    );
}

function ManageView({methods, labels, companyId = 0, canAdmin = false}: Props) {
    const [data, setData] = useState<CompanyDetail | null>(null);
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const [invite, setInvite] = useState({email: "", companyrole: "member", islearner: true});
    const [license, setLicense] = useState({offerid: "", seatcount: "", timestart: "", timeend: ""});
    const [newDomain, setNewDomain] = useState("");
    const [assignment, setAssignment] = useState({memberid: "", courseid: "", desired: true});
    const load = useCallback(async () => {
        try {
            setError("");
            setData(await callService<CompanyDetail>(methods.getCompany, {companyid: companyId}));
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : labels.errorgeneric);
        }
    }, [companyId, labels.errorgeneric, methods.getCompany]);
    useEffect(() => { void load(); }, [load]);
    const action = async (memberid: number, memberAction: string) => {
        try {
            const result = await callService<{message: string}>(methods.memberAction, {companyid: companyId, memberid, action: memberAction});
            setSuccess(result.message); await load();
        } catch (reason) { setError(reason instanceof Error ? reason.message : labels.errorgeneric); }
    };
    const approve = async (domainid: number) => {
        try {
            const result = await callService<{message: string}>(methods.approveDomain, {domainid});
            setSuccess(result.message); await load();
        } catch (reason) { setError(reason instanceof Error ? reason.message : labels.errorgeneric); }
    };
    const companyAction = async (selectedAction: string) => {
        try {
            const result = await callService<{message: string}>(methods.companyAction, {companyid: companyId, action: selectedAction});
            setSuccess(result.message); await load();
        } catch (reason) { setError(reason instanceof Error ? reason.message : labels.errorgeneric); }
    };
    const addDomain = async (event: React.FormEvent) => {
        event.preventDefault();
        try {
            const result = await callService<{message: string}>(methods.addDomain, {companyid: companyId, domain: newDomain});
            setSuccess(result.message); setNewDomain(""); await load();
        } catch (reason) { setError(reason instanceof Error ? reason.message : labels.errorgeneric); }
    };
    const licenseAction = async (licenseid: number, selectedAction: string) => {
        try {
            const result = await callService<{message: string}>(methods.licenseAction, {companyid: companyId, licenseid, action: selectedAction});
            setSuccess(result.message); await load();
        } catch (reason) { setError(reason instanceof Error ? reason.message : labels.errorgeneric); }
    };
    const sendInvite = async (event: React.FormEvent) => {
        event.preventDefault();
        try {
            const result = await callService<{message: string}>(methods.invite, {companyid: companyId, ...invite});
            setSuccess(result.message); setInvite({email: "", companyrole: "member", islearner: true}); await load();
        } catch (reason) { setError(reason instanceof Error ? reason.message : labels.errorgeneric); }
    };
    const addLicense = async (event: React.FormEvent) => {
        event.preventDefault();
        try {
            const result = await callService<{message: string}>(methods.addLicense, {
                companyid: companyId,
                offerid: Number(license.offerid) || 0,
                seatcount: Number(license.seatcount),
                timestart: license.timestart ? Math.floor(new Date(license.timestart).getTime() / 1000) : 0,
                timeend: license.timeend ? Math.floor(new Date(license.timeend).getTime() / 1000) : 0,
            });
            setSuccess(result.message); setLicense({offerid: "", seatcount: "", timestart: "", timeend: ""}); await load();
        } catch (reason) { setError(reason instanceof Error ? reason.message : labels.errorgeneric); }
    };
    const assignOptional = async (event: React.FormEvent) => {
        event.preventDefault();
        try {
            const result = await callService<{message: string}>(methods.assignOptional, {
                companyid: companyId,
                memberid: Number(assignment.memberid),
                courseid: Number(assignment.courseid),
                desired: assignment.desired,
            });
            setSuccess(result.message); await load();
        } catch (reason) { setError(reason instanceof Error ? reason.message : labels.errorgeneric); }
    };
    if (!data) return <><Notice error={error} success={success} /><div className={mcClasses("mc-empty mc-empty--centered")}>{labels.loading}</div></>;
    return <div className={mcClasses("mc-stack mc-stack--lg")}>
        <Notice error={error} success={success} />
        <div className="row g-3"><div className="col-md-4"><div className={mcClasses("mc-card")}><div className={mcClasses("mc-card-body")}><div className="text-muted">{labels.seatusage}</div><div className="fs-3 fw-semibold">{data.company.used} / {data.company.capacity}</div></div></div></div><div className="col-md-4"><div className={mcClasses("mc-card")}><div className={mcClasses("mc-card-body")}><div className="text-muted">{labels.members}</div><div className="fs-3 fw-semibold">{data.members.length}</div></div></div></div><div className="col-md-4"><div className={mcClasses("mc-card")}><div className={mcClasses("mc-card-body")}><div className="text-muted">{labels.status}</div><div className="fs-3 fw-semibold">{data.company.status}</div></div></div></div></div>
        <section className={mcClasses("mc-card")}><div className={mcClasses("mc-card-body")}><div className="d-flex justify-content-between gap-3 flex-wrap"><div><h3>{labels.domains}</h3><div className="d-flex gap-2 flex-wrap">{data.domains.map((domain) => <span key={domain.id} className="badge text-bg-light border">{domain.domain} · {domain.status} {canAdmin && domain.status === "pending" && <button className={mcClasses("mc-button mc-btn-soft py-1 px-2")} onClick={() => void approve(domain.id)}>{labels.approve}</button>}</span>)}</div></div>{canAdmin && <div className="d-flex gap-2 align-items-end"><form className="d-flex gap-2" onSubmit={addDomain}><input required className="form-control" placeholder="example.com" value={newDomain} onChange={(event) => setNewDomain(event.target.value)} /><button className={mcClasses("mc-button btn-mc-secondary")} type="submit">{labels.adddomain}</button></form><button className={mcClasses(data.company.status === "suspended" ? "mc-button btn-mc-primary" : "mc-button btn-mc-danger")} type="button" onClick={() => void companyAction(data.company.status === "suspended" ? "activate" : "suspend")}>{data.company.status === "suspended" ? labels.activate : labels.suspend}</button></div>}</div></div></section>
        <div className="row g-4"><div className="col-lg-6"><section className={mcClasses("mc-card h-100")}><div className={mcClasses("mc-card-body")}><h3>{labels.invitemember}</h3><form className="d-grid gap-3" onSubmit={sendInvite}><input required type="email" className="form-control" placeholder={labels.email} value={invite.email} onChange={(event) => setInvite({...invite, email: event.target.value})} /><select className="form-select" value={invite.companyrole} onChange={(event) => setInvite({...invite, companyrole: event.target.value})}><option value="member">{labels.member}</option><option value="manager">{labels.manager}</option></select><label className="form-check"><input type="checkbox" className="form-check-input" checked={invite.islearner} onChange={(event) => setInvite({...invite, islearner: event.target.checked})} /><span className="form-check-label">{labels.allocatelearnerseat}</span></label><button className={mcClasses("mc-button btn-mc-primary")} type="submit">{labels.sendinvite}</button></form></div></section></div>
            {canAdmin && <div className="col-lg-6"><section className={mcClasses("mc-card h-100")}><div className={mcClasses("mc-card-body")}><h3>{labels.addlicense}</h3><form className="d-grid gap-3" onSubmit={addLicense}><input required className="form-control" inputMode="numeric" placeholder={labels.offerid} value={license.offerid} onChange={(event) => setLicense({...license, offerid: event.target.value})} /><input required className="form-control" inputMode="numeric" placeholder={labels.seatcount} value={license.seatcount} onChange={(event) => setLicense({...license, seatcount: event.target.value})} /><div className="row g-2"><label className="col"><span className="form-label">{labels.startdate}</span><input className="form-control" type="date" value={license.timestart} onChange={(event) => setLicense({...license, timestart: event.target.value})} /></label><label className="col"><span className="form-label">{labels.enddate}</span><input className="form-control" type="date" value={license.timeend} onChange={(event) => setLicense({...license, timeend: event.target.value})} /></label></div><button className={mcClasses("mc-button btn-mc-primary")} type="submit">{labels.addlicense}</button></form></div></section></div>}
        </div>
        <section className={mcClasses("mc-card")}><div className={mcClasses("mc-card-body")}><div className="d-flex justify-content-between gap-3 flex-wrap align-items-end"><h3>{labels.members}</h3><form className="d-flex gap-2 flex-wrap align-items-end" onSubmit={assignOptional}><label><span className="form-label">{labels.member}</span><select required className="form-select" value={assignment.memberid} onChange={(event) => setAssignment({...assignment, memberid: event.target.value})}><option value="">{labels.member}</option>{data.members.filter((member) => member.islearner).map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}</select></label><label><span className="form-label">{labels.optionalcourse}</span><select required className="form-select" value={assignment.courseid} onChange={(event) => setAssignment({...assignment, courseid: event.target.value})}><option value="">{labels.optionalcourse}</option>{data.courses.filter((course) => course.accessmode === "optional").map((course) => <option key={course.courseid} value={course.courseid}>{course.fullname}</option>)}</select></label><label><span className="form-label">{labels.action}</span><select className="form-select" value={assignment.desired ? "assign" : "remove"} onChange={(event) => setAssignment({...assignment, desired: event.target.value === "assign"})}><option value="assign">{labels.assign}</option><option value="remove">{labels.remove}</option></select></label><button className={mcClasses("mc-button btn-mc-secondary")} type="submit">{labels.apply}</button></form></div></div><div className="table-responsive"><table className="table mb-0"><thead><tr><th>{labels.member}</th><th>{labels.role}</th><th>{labels.seatstate}</th><th>{labels.actions}</th></tr></thead><tbody>{data.members.map((member) => <tr key={member.id}><td>{member.name}<div className="text-muted small">{member.email}</div></td><td>{member.companyrole}</td><td>{member.seatstate}</td><td className="d-flex gap-2 flex-wrap">{member.seatstate === "pending" && <button className={mcClasses("mc-button btn-mc-primary py-1 px-2")} onClick={() => void action(member.id, "activate")}>{labels.activateseat}</button>}{member.companyrole === "member" ? <button className={mcClasses("mc-button btn-mc-secondary py-1 px-2")} onClick={() => void action(member.id, "promote")}>{labels.promote}</button> : member.companyrole === "manager" && <button className={mcClasses("mc-button btn-mc-secondary py-1 px-2")} onClick={() => void action(member.id, "demote")}>{labels.demote}</button>}<button className={mcClasses("mc-button btn-mc-danger py-1 px-2")} onClick={() => void action(member.id, "remove")}>{labels.remove}</button></td></tr>)}</tbody></table></div></section>
        <section className={mcClasses("mc-card")}><div className={mcClasses("mc-card-body")}><h3>{labels.licensesandcourses}</h3><div className="row g-4"><div className="col-lg-6"><ul className="list-group">{data.licenses.map((item) => <li key={item.id} className="list-group-item"><div className="d-flex justify-content-between gap-2"><span>#{item.id} · {item.seatcount} {labels.seats}</span><span>{item.status} · {dateLabel(item.timeend)}</span></div>{canAdmin && <div className="d-flex gap-2 mt-2">{item.status === "active" ? <button className={mcClasses("mc-button btn-mc-secondary py-1 px-2")} onClick={() => void licenseAction(item.id, "suspend")}>{labels.suspend}</button> : item.status === "suspended" && <button className={mcClasses("mc-button btn-mc-primary py-1 px-2")} onClick={() => void licenseAction(item.id, "activate")}>{labels.activate}</button>} {!['cancelled', 'expired'].includes(item.status) && <button className={mcClasses("mc-button btn-mc-danger py-1 px-2")} onClick={() => void licenseAction(item.id, "cancel")}>{labels.cancel}</button>}</div>}</li>)}</ul></div><div className="col-lg-6"><ul className="list-group">{data.courses.map((course) => <li key={course.courseid} className="list-group-item d-flex justify-content-between"><span>{course.fullname}</span><span>{course.accessmode}</span></li>)}</ul></div></div></div></section>
        <section className={mcClasses("mc-card")}><div className={mcClasses("mc-card-body")}><h3>{labels.auditlog}</h3></div><div className="table-responsive"><table className="table mb-0"><thead><tr><th>{labels.date}</th><th>{labels.action}</th><th>{labels.object}</th><th>{labels.actor}</th></tr></thead><tbody>{data.audit.map((item) => <tr key={item.id}><td>{dateLabel(item.timecreated)}</td><td>{item.action}</td><td>{item.objecttype} #{item.objectid}</td><td>{item.actoruserid || labels.system}</td></tr>)}</tbody></table></div></section>
    </div>;
}

function LearningView({methods, labels}: Props) {
    const [data, setData] = useState<LearningData | null>(null);
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const load = useCallback(async () => {
        try { setData(await callService<LearningData>(methods.getLearning, {})); }
        catch (reason) { setError(reason instanceof Error ? reason.message : labels.errorgeneric); }
    }, [labels.errorgeneric, methods.getLearning]);
    useEffect(() => { void load(); }, [load]);
    const toggle = async (courseid: number, desired: boolean) => {
        try {
            const result = await callService<{message: string}>(methods.setOptional, {courseid, desired});
            setSuccess(result.message); await load();
        } catch (reason) { setError(reason instanceof Error ? reason.message : labels.errorgeneric); }
    };
    if (!data) return <><Notice error={error} success={success} /><div className={mcClasses("mc-empty mc-empty--centered")}>{labels.loading}</div></>;
    if (!data.hascompany) return <div className={mcClasses("mc-empty mc-empty--centered")}><span className={mcClasses("mc-empty__icon")}><i className="bi bi-buildings" /></span><p className={mcClasses("mc-empty__title")}>{labels.nocompanymembership}</p><p className={mcClasses("mc-empty__text")}>{labels.nocompanymembershipdesc}</p></div>;
    return <div className={mcClasses("mc-stack mc-stack--lg")}><Notice error={error} success={success} /><section className={mcClasses("mc-card")}><div className={mcClasses("mc-card-body")}><h2>{data.company?.name}</h2><p>{labels.seatstate}: <strong>{data.company?.seatstate}</strong></p>{data.company?.seatstate === "pending" && <div className="alert alert-warning">{labels.waitlistmessage}</div>}</div></section><div className="row g-4">{data.courses.map((course) => <div className="col-md-6 col-xl-4" key={course.courseid}><article className={mcClasses("mc-card h-100")}><div className={mcClasses("mc-card-body d-flex flex-column")}><span className="badge text-bg-light border align-self-start mb-3">{course.accessmode}</span><h3>{course.fullname}</h3><p className="text-muted">{labels.accessuntil} {dateLabel(course.timeend)}</p><div className="mt-auto">{course.enrolled ? <div className="d-flex gap-2"><a className={mcClasses("mc-button btn-mc-primary")} href={course.url}>{labels.opencourse}</a>{course.accessmode === "optional" && <button className={mcClasses("mc-button btn-mc-secondary")} onClick={() => void toggle(course.courseid, false)}>{labels.withdraw}</button>}</div> : course.accessmode === "optional" && <button className={mcClasses("mc-button btn-mc-primary")} disabled={data.company?.seatstate !== "active" && data.company?.seatstate !== "grace"} onClick={() => void toggle(course.courseid, true)}>{labels.enrol}</button>}</div></div></article></div>)}</div></div>;
}

function CheckoutView({methods, labels}: Props) {
    const [offers, setOffers] = useState<Offer[]>([]);
    const [form, setForm] = useState({offerid: "", seatcount: "", companyname: "", domain: "", purchasetype: "additional"});
    const [error, setError] = useState("");
    useEffect(() => { void callService<{offers: Offer[]}>(methods.purchaseOffers, {}).then((result) => setOffers(result.offers)).catch((reason) => setError(reason instanceof Error ? reason.message : labels.errorgeneric)); }, [labels.errorgeneric, methods.purchaseOffers]);
    const selected = useMemo(() => offers.find((offer) => offer.id === Number(form.offerid)), [form.offerid, offers]);
    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        try {
            const result = await callService<{checkouturl: string}>(methods.startPurchase, {...form, offerid: Number(form.offerid), seatcount: Number(form.seatcount)});
            window.location.assign(result.checkouturl);
        } catch (reason) { setError(reason instanceof Error ? reason.message : labels.errorgeneric); }
    };
    return <div className="row justify-content-center"><div className="col-xl-8"><Notice error={error} success="" /><section className={mcClasses("mc-card")}><div className={mcClasses("mc-card-body")}><form className="d-grid gap-3" onSubmit={submit}><label><span className="form-label">{labels.licenseoffer}</span><select required className="form-select" value={form.offerid} onChange={(event) => setForm({...form, offerid: event.target.value, seatcount: offers.find((offer) => offer.id === Number(event.target.value))?.minseats.toString() ?? ""})}><option value="">{labels.selectoffer}</option>{offers.map((offer) => <option key={offer.id} value={offer.id}>{offer.name} · {money(offer.unitprice, offer.currency)} / {labels.seat}</option>)}</select></label>{selected && <div className="alert alert-info">{selected.durationdays} {labels.days} · {selected.courses.length} {labels.courses} · {selected.minseats}–{selected.maxseats || "∞"} {labels.seats}</div>}<label><span className="form-label">{labels.companyname}</span><input required className="form-control" value={form.companyname} onChange={(event) => setForm({...form, companyname: event.target.value})} /></label><label><span className="form-label">{labels.companydomain}</span><input required className="form-control" placeholder="example.com" value={form.domain} onChange={(event) => setForm({...form, domain: event.target.value})} /></label><label><span className="form-label">{labels.seatcount}</span><input required type="number" className="form-control" min={selected?.minseats ?? 1} max={selected?.maxseats || undefined} value={form.seatcount} onChange={(event) => setForm({...form, seatcount: event.target.value})} /></label><label><span className="form-label">{labels.purchasetype}</span><select className="form-select" value={form.purchasetype} onChange={(event) => setForm({...form, purchasetype: event.target.value})}><option value="additional">{labels.additionallicense}</option><option value="renewal">{labels.renewal}</option></select></label>{selected && Number(form.seatcount) > 0 && <p className="fs-4 fw-semibold">{labels.total}: {money(selected.unitprice * Number(form.seatcount), selected.currency)}</p>}<button className={mcClasses("mc-button btn-mc-primary")} type="submit">{labels.continuetocheckout}</button></form></div></section></div></div>;
}

export default function CompanyLicensing(props: Props) {
    useModernCommerceClassSync();
    if (props.mode === "admin") return <AdminView {...props} />;
    if (props.mode === "manage") return <ManageView {...props} />;
    if (props.mode === "checkout") return <CheckoutView {...props} />;
    return <LearningView {...props} />;
}
