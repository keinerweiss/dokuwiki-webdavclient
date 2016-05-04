CREATE TABLE connections (
    id integer primary key asc,
    uri text,
    displayname text,
    synctoken integer,
    description text,
    username text,
    password text,
    dwuser text,
    type text,
    syncinterval integer,
    lastsynced integer,
    ctag text,
    active integer,
    write integer
);

CREATE TABLE calendarobjects (
    id integer primary key asc,
    calendardata blob,
    uri text,
    calendarid integer,
    lastmodified integer,
    etag text,
    size integer,
    componenttype text,
    firstoccurence integer,
    lastoccurence integer,
    uid text
);

CREATE TABLE calendarchanges (
    id integer primary key asc,
    uri text,
    synctoken integer,
    calendarid integer,
    operation integer
);

CREATE INDEX calendarid_synctoken ON calendarchanges (calendarid, synctoken);

CREATE TABLE addressbookobjects (
    id integer primary key asc,
    contactdata blob,
    uri text,
    addressbookid integer,
    lastmodified integer,
    etag text,
    size integer,
    componenttype text,
    uid text
);

CREATE TABLE addressbookchanges (
    id integer primary key asc,
    uri text,
    synctoken integer,
    addressbookid integer,
    operation integer
);

CREATE INDEX addressbookid_synctoken ON addressbookchanges (addressbookid, synctoken);
