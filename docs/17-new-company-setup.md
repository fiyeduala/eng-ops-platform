# Setting up a new company

**Who this is for:** the person configuring this system for a company for the first time. You do
not need to be a developer, you will not be asked to edit a file, and nothing in this document
requires anybody to open the database. If a step here cannot be completed on a screen, that is a
defect in the system, not something for you to work around — see *When a step will not complete*
at the end.

**How long it takes:** about two hours for the walk below, spread over as long as you like. Nothing
has to be finished in one sitting, and every step can be changed later.

**What you need before you start:** an account that was created for you, and the password you were
given. If nobody has given you one, skip to *Before any of this: the first account*.

---

## Before any of this: the first account

Installing the software does not create a company and does not create a person. Somebody with
access to the server runs one command once:

```
php artisan engops:install --name="Your Company" --admin-name="Your Name" --admin-email=you@example.com
```

It prints a password once and never again. That password gets you in and then immediately asks you
to choose a new one.

There is no way to do this from a browser, by design: an installation that could create its own
first administrator from a public page would let anybody else do the same. If you are reading this
because you cannot sign in, the person who set the server up is who you need.

---

## The two people in this walk

Setting up a company takes **two different roles**, and it is worth knowing why before you start —
otherwise the first refusal looks like a bug.

**The owner** is whoever the company was installed for. They create accounts and hand out roles.

**The administrator** holds the *System Administrator* role. They build the company: the org chart,
the vocabulary, the numbering, the registers, the approval routes.

The administrator **cannot hand out roles containing permissions they do not hold themselves.**
That is deliberate. It means a System Administrator cannot quietly grant themselves the ability to
read confidential HR cases or approve a payment, and it means staffing the company stays with the
owner. If you are doing both jobs, you will be signed in as the owner for step 3 and as the
administrator for everything else.

---

## Where everything in this document lives

Two menus in the sidebar on the left: **Organization**, which holds the chart and the people on
it, and **Administration**, which holds everything you configure. Every screen named below is on
one of those two, and each step says which.

You will only see the items your role allows. A menu that looks shorter than this document
describes is not broken — it is showing you what you can actually use. If something you need is
missing entirely, that is a role question for the owner, not a system fault.

---

## The walk

Do these in order. Each step uses something the one before it produced.

### 1. Draw the org chart

**Organization › Org chart**

Create your divisions, departments, sections and teams. You do not have to use all four levels —
a company where departments sit directly under the company and teams sit directly under
departments is completely normal and nothing later in this document assumes otherwise.

Two things are decided here that are hard to change later, so it is worth a moment:

- **Who a department reports to.** This drives who approves what, all the way through the system.
- **Whether a unit is a department or a section.** Reports group by department.

You can move a unit afterwards — it keeps its history and its in-flight approvals move with it —
but it is easier to get roughly right now.

### 2. Add the job titles

**Organization › Positions**

A job title is the thing a person is hired into. Keep them generic ("Site Engineer", not "Site
Engineer — Bonny Island"): the project someone is working on is recorded separately, and a title per
project produces a list nobody can read within a year.

### 3. Create the accounts and hand out the roles

*This step is the owner's.*

**Administration › User accounts** to create the sign-in, then **Organization › People** to create the
personnel record that places them on the chart you drew in step 1.

Both are needed. An account with no employee record has no place in the company and therefore
reaches nothing — which looks, from the inside, exactly like a permissions fault.

Then **Administration › Roles & permissions** to give each person their role. The roles that ship
with the system cover most companies; adjusting them is step 9, not now.

### 4. Decide the words your records move through

**Administration › Statuses & transitions**

Every kind of record — a document, a project, a purchase order — moves through a set of states, and
you choose what those are called and which one can follow which. If your company says "For
Construction" where another says "Approved", say that here and the whole system will say it back to
you.

Two things to set deliberately:

- **Which status means the record is finished.** Reports, dashboards and overdue chasing all read
  this, not the name.
- **Which status a rejection lands on.** There is no universal answer: on some routes a rejection
  sends a document back to draft, on others it ends it. This is a decision about how your company
  works.

### 5. Decide what your reference numbers look like

**Administration › Reference numbering**

You choose the shape: a prefix, a project or department code, a year, a serial. `HES-CIV-2026-0001`
and `DOC-0001` are both fine.

The numbers have no gaps and no duplicates, and that is enforced rather than hoped for — two people
creating a document at the same instant get two different numbers. Once a scheme has issued numbers,
change it only with a reason; consistency is most of the value of a reference.

### 6. Create the registers

**Administration › Document registers**

A register is a kind of document your company keeps: method statements, drawings, inspection
reports, certificates. For each one you set who can see it, which numbering scheme it uses, and
whether it is revision-controlled.

This is the step most companies under-think. A register is cheap to create now and awkward to split
later, once things are filed in it.

### 7. Build the approval route

**Administration › Workflows**

A workflow is the path a record takes to get approved: who reviews it, in what order, and what
happens if nobody does.

Approvals are addressed **to a role, not to a person.** "The Engineering Manager approves this", not
"Chidi approves this". That is what makes the route survive Chidi going on leave, changing job or
leaving the company — the system resolves the role to whoever holds it on the day.

Set the time limit on each stage. If a stage is not acted on in time the system escalates it upward
rather than letting it sit, and a route with no limits is a route that quietly stops.

When you are satisfied, **publish** it. An unpublished workflow is a draft and will not route
anything.

### 8. Join the register to the route

**Administration › Document registers** → edit the register from step 6 → set its approval workflow
to the one from step 7.

**This is the step people forget.** Everything above can be complete and correct and nothing will
route, because the register does not yet know which route to use. If documents are being created and
no approvals are appearing, this is why.

### 9. Only now, adjust the roles

**Administration › Roles & permissions**

Having walked the whole thing, you can see what your company actually needs. Adjust the seeded roles
rather than creating new ones where you can — the seeded roles are what the approval routes and the
escalation ladder refer to.

One caution: the permission list contains a small number of genuinely dangerous entries — system
settings, confidential personnel records, supplier bank details, the ability to complete someone's
exit. Those are marked, and they should be held by a named few rather than by a job family.

### 10. Say what the company counts, and where it is shown

**Administration › Measures**, then **Administration › Boards**

A measure is a number the company cares about: documents awaiting approval, overdue inspections,
open incidents. A board is where those numbers appear.

This is worth doing last rather than first. You now know what your registers and statuses are called,
which is what a measure has to be defined in terms of.

### 11. Set what gets chased before it lapses

**Administration › Renewals & expiry**

Insurance certificates, permits, training records, contracts — anything with an expiry date. You
choose how far ahead the warning starts, who hears it, and whether it keeps chasing after the date
has passed.

One watch covers a whole register, so this is a short screen with a lot of value. It is also the
quietest thing in the system to switch off, so make sure more than one person knows what is being
watched.

---

## Check your work

Do not check it by reading the configuration screens back. Check it by using the system:

1. Ask somebody to create a real document in a register you built.
2. Ask them to submit it.
3. Confirm the person the route names sees it in their approvals list.
4. Have them approve it.
5. Confirm the document ends on the status you chose in step 4, with a reference number in the
   shape you chose in step 5.

If all five work, the company is configured. If step 3 produces nothing, go back to step 8.

---

## Things that are not faults

**A menu item you were told about is missing.** You are being shown what your role allows. Ask the
owner.

**You cannot grant a permission you can see in the list.** You do not hold it yourself. This is the
rule described at the top, working.

**A refusal page says to ask whoever administers the system.** That is the correct page. The record
exists and your role does not reach it.

**A rejected document goes somewhere you did not expect.** That is the status you chose in step 4.
Change it there rather than working around it.

---

## When a step will not complete

Everything above is meant to be doable on a screen by somebody who is not a developer. If you hit a
step that genuinely cannot be completed — a screen that does not exist, a field that will not save,
a dropdown that is empty when it should not be — that is a defect and it is worth reporting rather
than working around, because the alternative to configuring it on a screen is somebody editing the
database, and that is how a company ends up with data nothing else in the system understands.

Include the reference the error page gives you if there is one. It is a timestamp, and it is what
turns "it broke this morning" into a specific second in the log.
