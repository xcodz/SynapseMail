#!/usr/local/cpanel/3rdparty/bin/perl
#WHMADDON:synapsemail:SynapseMail Webmail:synapsemail.png
#ACLS:all
#
# SynapseMail WHM plugin: WHM page (Plugins >> SynapseMail Webmail).
# Copyright (c) 2026 SynapseCyber.io. All rights reserved. Developed by Milad Ahmadipour (https://milad.io).
# Licensed under CC BY-NC-ND 4.0 (https://creativecommons.org/licenses/by-nc-nd/4.0/).
#
# Shows the status of the Webmail redirect and runs smwhmplugin for the buttons. Only WHM users with the
# "all" ACL (root) can open it. Changes are POST only, from a fixed list, with a same-origin check on top of
# WHM's session token in the URL. Actions run in the background (a cpsrvd restart must not cut them off).

use strict;
use warnings;
BEGIN { unshift @INC, '/usr/local/cpanel'; }
use POSIX ();
use MIME::Base64 ();
use JSON::PP ();

my $SM      = '/usr/local/synapsemail-whm/smwhmplugin';
my $ASSETS  = '/usr/local/synapsemail-whm/whm';
my $VAR     = '/var/lib/synapsemail-whm';
my $LOG     = '/var/log/synapsemail-whm/smwhmplugin.log';
my $GITHUB  = 'https://github.com/xcodz/SynapseMail';
my $SELF    = 'index.cgi';

# ---------------------------------------------------------------- access

my $allowed = 0;
if ( eval { require Whostmgr::ACLS; 1 } ) {
    Whostmgr::ACLS::init_acls();
    $allowed = Whostmgr::ACLS::checkacl('all') ? 1 : 0;
}
if ( !$allowed ) {
    print "Status: 403 Forbidden\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nAccess denied: this page needs root (the \"all\" WHM privilege).\n";
    exit 0;
}

# ---------------------------------------------------------------- input

my %form = parse_form();

sub parse_form {
    if ( eval { require Cpanel::Form; 1 } ) {
        my %f = Cpanel::Form::parseform();
        return %f;
    }
    my $data = $ENV{QUERY_STRING} // '';
    if ( ( $ENV{REQUEST_METHOD} // '' ) eq 'POST' ) {
        my $len = $ENV{CONTENT_LENGTH} // 0;
        $len = 65536 if $len > 65536;
        my $body = '';
        read( STDIN, $body, $len ) if $len > 0;
        $data = $data ? "$data&$body" : $body;
    }
    my %f;
    for my $pair ( split /[&;]/, $data ) {
        my ( $k, $v ) = split /=/, $pair, 2;
        next if !defined $k;
        for ( $k, $v ) { next if !defined; tr/+/ /; s/%([0-9A-Fa-f]{2})/chr(hex($1))/ge; }
        $f{$k} = $v // '';
    }
    return %f;
}

sub host_only { # "https://Server.Example:2087/x" or "server.example:2087" -> "server.example"
    my $v = lc( shift // '' );
    $v =~ s{^[a-z][a-z0-9+.-]*://}{};
    $v =~ s{[/?#].*$}{};
    $v =~ s{^\[([^\]]+)\](?::\d+)?$}{$1} or $v =~ s{:\d+$}{};
    return $v;
}

# Extra check on top of WHM's own session token: the browser's Origin/Referer must name this server.
# Hosts are compared without ports (cpsrvd may pass Host without :2087), and a proxy's forwarded host counts
# (WHM opened through whm.<domain>). Returns '' when fine, else what did not match.
sub origin_problem {
    my %ours = map { ( host_only($_) => 1 ) } grep { defined && $_ ne '' } @ENV{qw(HTTP_HOST SERVER_NAME HTTP_X_FORWARDED_HOST)};
    for my $h (qw(HTTP_ORIGIN HTTP_REFERER)) {
        my $v = $ENV{$h} // '';
        next if $v eq '' || $v eq 'null';
        my $o = host_only($v);
        return "$o is not " . join( ' or ', sort keys %ours ) if !$ours{$o};
    }
    return '';
}

sub h {
    my $s = shift // '';
    $s =~ s/&/&amp;/g; $s =~ s/</&lt;/g; $s =~ s/>/&gt;/g; $s =~ s/"/&quot;/g; $s =~ s/'/&#39;/g;
    return $s;
}

sub slurp {
    my ($file) = @_;
    open( my $fh, '<', $file ) or return '';
    local $/;
    my $c = <$fh>;
    close $fh;
    return $c // '';
}

sub spit {
    my ( $file, $content ) = @_;
    open( my $fh, '>', $file ) or return;
    print {$fh} $content;
    close $fh;
    chmod 0600, $file;
}

sub tail_lines {
    my ( $file, $n ) = @_;
    my @l = split /\n/, slurp($file);
    @l = @l[ -$n .. -1 ] if @l > $n;
    return join( "\n", @l );
}

sub capture { # run a command without a shell, return its stdout
    my @cmd = @_;
    my $pid = open( my $fh, '-|' );
    return '' if !defined $pid;
    if ( !$pid ) {
        open( STDERR, '>', '/dev/null' );
        exec( {$cmd[0]} @cmd ) or POSIX::_exit(127);
    }
    local $/;
    my $out = <$fh>;
    close $fh;
    return $out // '';
}

# ---------------------------------------------------------------- background actions

my %ACTIONS = (
    check     => [ 'Check the logins',          [ '-check' ] ],
    repair    => [ 'Rebuild the login theme',   [ '-repair' ] ],
    restart   => [ 'Restart the login service', [ '-restart' ] ],
    enable    => [ 'Turn the redirect on',      [ '-enable', '-y' ] ],
    rollback  => [ 'Turn the redirect off',     [ '-rollback' ] ],
    url       => [ 'Change the address',        [ '-url' ] ],
    latest    => [ 'Check for updates',         [ '-latest' ] ],
    update    => [ 'Update the plugin',         [ '-update', '-y' ] ],
    uninstall => [ 'Uninstall the plugin',      [ '-uninstall', '-y' ] ],
);

sub running {
    my $pid = slurp("$VAR/web-action.pid");
    return 0 if $pid !~ /^(\d+)$/;
    return kill( 0, $1 ) ? 1 : 0;
}

sub start_action {
    my ( $name, @extra ) = @_;
    return 'Another action is still running.' if running();
    my ( $label, $args ) = @{ $ACTIONS{$name} };
    my @cmd = ( $SM, @$args, @extra, '--plain' );
    mkdir $VAR, 0700;
    spit( "$VAR/web-action", join( "\t", $label, time() ) );
    unlink "$VAR/web-action.rc";
    spit( "$VAR/web-action.log", '' );
    my $pid = fork();
    return 'Could not start the action.' if !defined $pid;
    if ( $pid == 0 ) {
        POSIX::setsid();
        my $grand = fork();
        POSIX::_exit(0) if !defined $grand || $grand;
        chdir '/';
        open( STDIN,  '<',  '/dev/null' );
        open( STDOUT, '>>', "$VAR/web-action.log" );
        open( STDERR, '>&', \*STDOUT );
        spit( "$VAR/web-action.pid", $$ );
        sleep 1;    # let this page answer before anything restarts cpsrvd
        my $rc = system {$cmd[0]} @cmd;
        $rc = $rc == -1 ? 127 : $rc >> 8;
        spit( "$VAR/web-action.rc", $rc );
        unlink "$VAR/web-action.pid";
        POSIX::_exit(0);
    }
    waitpid( $pid, 0 );
    return '';
}

my $notice = '';
my $view   = ( $form{view} // '' ) eq 'uninstall' ? 'uninstall' : 'main';
if ( ( $ENV{REQUEST_METHOD} // '' ) eq 'POST' ) {
    my $action = $form{action} // '';
    if ( my $why = origin_problem() ) {
        $notice = "The request came from another site and was ignored ($why).";
    }
    elsif ( !exists $ACTIONS{$action} ) {
        $notice = 'Unknown action.';
    }
    elsif ( $action eq 'url' ) {
        my $u = $form{url} // '';
        $u =~ s/^\s+|\s+$//g;
        if ( $u !~ m{^https://[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(:[0-9]{1,5})?(/[A-Za-z0-9._~%/+-]*)?$} ) {
            $notice = 'Use an https:// address without spaces, quotes or a query, for example https://example.com/mail';
        }
        else {
            $notice = start_action( 'url', $u );
        }
    }
    elsif ( $action eq 'uninstall' ) {
        # second step only: the word typed on the confirm page
        my $typed = lc( $form{confirm_text} // '' );
        $typed =~ s/^\s+|\s+$//g;
        if ( $typed ne 'uninstall' ) {
            $notice = 'Type the word uninstall to confirm. Nothing was removed.';
            $view   = 'uninstall';
        }
        else {
            $notice = start_action('uninstall');
        }
    }
    else {
        $notice = start_action($action);
    }
    if ( $notice eq '' ) {
        # Post/Redirect/Get: a reload never repeats the action
        print "Status: 303 See Other\r\nLocation: $SELF\r\n\r\n";
        exit 0;
    }
}

# ---------------------------------------------------------------- data

my $status = eval { JSON::PP->new->decode( capture( $SM, '-json' ) ) } || {};
my $busy   = running();
my ( $last_label, $last_time ) = split /\t/, slurp("$VAR/web-action");
my $last_rc  = slurp("$VAR/web-action.rc");
my $last_out = tail_lines( "$VAR/web-action.log", 14 );
$last_out =~ s/\e\[[0-9;]*m//g;
my $log = tail_lines( $LOG, 60 );

sub img_data {
    my ( $file, $type ) = @_;
    my $c = slurp($file);
    return $c eq '' ? '' : "data:$type;base64," . MIME::Base64::encode_base64( $c, '' );
}
my $banner = img_data( "$ASSETS/banner.webp",     'image/webp' );
my $icon   = img_data( "$ASSETS/synapsemail.png", 'image/png' );

sub when { # 2026-11-25T12:27:29Z ok -> 2026-11-25 12:27 UTC, ok
    my $v = shift // '';
    return $v =~ /^(\d{4}-\d\d-\d\d)T(\d\d:\d\d)(?::\d\d)?Z\s*(.*)$/ ? "$1 $2 UTC" . ( $3 ne '' ? ", $3" : '' ) : $v;
}

sub vcmp {
    my @a = split /\./, shift;
    my @b = split /\./, shift;
    for my $i ( 0 .. 2 ) { my $d = ( $a[$i] // 0 ) <=> ( $b[$i] // 0 ); return $d if $d; }
    return 0;
}

# small line icons (24x24, stroke = currentColor)
my %ICON = (
    check   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
    rebuild => '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
    restart => '<rect x="2" y="3" width="20" height="7" rx="2"/><rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 6.5h.01M6 17.5h.01"/>',
    power   => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
    update  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
    search  => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
    mail    => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
    globe   => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20a15 15 0 0 1 0-20"/>',
    link    => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    shield  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/>',
    github  => '<path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3 0 6-2 6-5.5.08-1.25-.27-2.48-1-3.5.28-1.15.28-2.35 0-3.5 0 0-1 0-3 1.5-2.64-.5-5.36-.5-8 0C6 2 5 2 5 2c-.3 1.15-.3 2.35 0 3.5A5.4 5.4 0 0 0 4 9c0 3.5 3 5.5 6 5.5-.39.49-.68 1.05-.85 1.65-.17.6-.22 1.23-.15 1.85v4"/><path d="M9 18c-4.51 2-5-2-7-2"/>',
    trash   => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
    back    => '<path d="m15 18-6-6 6-6"/>',
    terminal=> '<path d="m4 17 6-6-6-6"/><path d="M12 19h8"/>',
);
sub icon { my ( $n, $c ) = @_; return '<svg class="smw-i' . ( $c ? " $c" : '' ) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $ICON{$n} . '</svg>'; }

sub action_btn { # a POST button with an icon
    my ( $action, $label, $icon, $class, $hint ) = @_;
    return qq{<form method="post" action="$SELF" class="smw-act-form"><input type="hidden" name="action" value="} . h($action) . qq{">}
      . qq{<button type="submit" class="smw-act } . ( $class // '' ) . qq{"} . ( $busy ? ' disabled' : '' ) . qq{>}
      . icon($icon) . qq{<span><b>} . h($label) . qq{</b>} . ( $hint ? '<small>' . h($hint) . '</small>' : '' ) . qq{</span></button></form>};
}

# ---------------------------------------------------------------- page

my $title = 'SynapseMail Webmail';
print "Content-Type: text/html; charset=utf-8\r\nCache-Control: no-store\r\nX-Frame-Options: SAMEORIGIN\r\n\r\n";
my $chrome = eval {
    require Whostmgr::HTMLInterface;
    Whostmgr::HTMLInterface::defheader( $title, '/addon_plugins/synapsemail.png', '/cgi/synapsemail/index.cgi' );
    1;
};
if ( !$chrome ) {
    print qq{<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>} . h($title) . qq{</title></head><body>};
}
print qq{<meta http-equiv="refresh" content="2">} if $busy;

my $installed = $status->{installed} ? 1 : 0;
my $on        = $status->{enabled}   ? 1 : 0;
my $paused    = $status->{paused} // '';
my ( $mode, $mode_text, $mode_hint ) =
    !$installed     ? ( 'off',  'Not installed', 'Install with the one-line command.' )
  : $on             ? ( 'on',   'Redirect on',   'Webmail logins open your SynapseMail address.' )
  : $paused ne ''   ? ( 'warn', 'Paused',        "Paused $paused. Turn it on again when you are ready." )
  :                   ( 'off',  'Redirect off',  'Webmail uses cPanel\'s own login (Roundcube).' );
my $ver    = $status->{version} // '?';
my $latest = $status->{latest} // '';
my $newer  = $latest ne '' && vcmp( $latest, $ver ) > 0;
my $url    = $status->{url} // '';
my %detail = ( ( $status->{check_detail} // '' ) =~ /(\w+)=(ok|fail)/g );
my ($detail_when) = ( $status->{check_detail} // '' ) =~ /(\d{4}-\d\d-\d\d \d\d:\d\d UTC)/;

print <<"CSS";
<style>
.smw{--navy:#06182b;--navy2:#0b2742;--cyan:#22d3ee;--blue:#3b82f6;--violet:#8b5cf6;--card:#fff;--line:#dfe6ef;--text:#132033;--muted:#5d6b7e;
  --ok:#0f9d6b;--okbg:#e3f6ee;--warn:#b26a00;--warnbg:#fdf2df;--bad:#c43a2c;--badbg:#fdecea;--accent:#0b7bc2;
  font:14px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--text);max-width:1120px;margin:0 auto;padding:16px 8px 48px}
.smw *{box-sizing:border-box}
.smw a{color:var(--accent)}
.smw-i{width:20px;height:20px;flex:none;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
/* hero */
.smw-hero{position:relative;overflow:hidden;border-radius:18px;padding:26px 28px 24px;color:#e6f1fb;
  background:radial-gradient(900px 300px at 85% -10%,rgba(34,211,238,.20),transparent 60%),radial-gradient(700px 300px at 0% 120%,rgba(139,92,246,.22),transparent 60%),linear-gradient(135deg,var(--navy),var(--navy2))}
.smw-hero::before{content:"";position:absolute;inset:0;opacity:.18;pointer-events:none;
  background-image:radial-gradient(circle at 1px 1px,#7dd3fc 1px,transparent 1.5px);background-size:22px 22px;mask-image:linear-gradient(90deg,transparent,#000 40%,#000 70%,transparent)}
.smw-hero-top{position:relative;display:flex;flex-wrap:wrap;gap:18px 24px;align-items:center;justify-content:space-between}
.smw-brand{display:flex;gap:16px;align-items:center;min-width:0}
.smw-brand img{width:58px;height:58px;filter:drop-shadow(0 0 14px rgba(34,211,238,.45))}
.smw-brand h1{margin:0;font-size:24px;letter-spacing:-.01em;color:#fff}
.smw-brand p{margin:2px 0 0;color:#a9c0d6}
.smw-banner{height:52px;width:auto;border-radius:10px;opacity:.95}
.smw-pill{display:inline-flex;align-items:center;gap:8px;padding:5px 12px 5px 10px;border-radius:999px;font-weight:650;font-size:13px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);color:#fff}
.smw-dot{width:9px;height:9px;border-radius:50%;background:#94a3b8;flex:none}
.smw-pill.on .smw-dot{background:#34d399;box-shadow:0 0 0 0 rgba(52,211,153,.7);animation:smwpulse 2s infinite}
.smw-pill.warn .smw-dot{background:#fbbf24}
\@keyframes smwpulse{0%{box-shadow:0 0 0 0 rgba(52,211,153,.6)}70%{box-shadow:0 0 0 9px rgba(52,211,153,0)}100%{box-shadow:0 0 0 0 rgba(52,211,153,0)}}
.smw-meta{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;margin-top:10px;color:#a9c0d6;font-size:13px}
.smw-meta a{color:#7dd3fc;display:inline-flex;gap:6px;align-items:center;text-decoration:none}
.smw-meta a:hover{text-decoration:underline}
.smw-meta .smw-i{width:16px;height:16px}
/* flow diagram */
.smw-flow{position:relative;display:grid;grid-template-columns:minmax(0,1fr) 120px minmax(0,1.1fr);gap:14px;align-items:center;margin-top:22px}
.smw-src{display:grid;gap:8px}
.smw-chip{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);font:600 13px ui-monospace,SFMono-Regular,Menlo,monospace;color:#dbeafe;min-width:0}
.smw-chip small{margin-left:auto;font:12px system-ui,sans-serif;color:#94a3b8}
.smw-chip .smw-i{width:16px;height:16px;color:#7dd3fc}
.smw-wire{position:relative;height:2px;background:linear-gradient(90deg,rgba(34,211,238,.15),rgba(34,211,238,.9),rgba(139,92,246,.9));border-radius:2px}
.smw-wire::after{content:"";position:absolute;right:-2px;top:-5px;border:6px solid transparent;border-left:8px solid #a78bfa}
.smw-wire span{position:absolute;top:-4px;left:0;width:10px;height:10px;border-radius:50%;background:#67e8f9;box-shadow:0 0 12px #22d3ee;animation:smwrun 2.4s linear infinite}
.smw.off .smw-wire{background:repeating-linear-gradient(90deg,rgba(148,163,184,.5) 0 6px,transparent 6px 12px)}
.smw.off .smw-wire span,.smw.off .smw-wire::after{display:none}
\@keyframes smwrun{from{left:0;opacity:0}15%{opacity:1}85%{opacity:1}to{left:calc(100% - 10px);opacity:0}}
.smw-dest{padding:16px 18px;border-radius:14px;background:linear-gradient(135deg,rgba(34,211,238,.16),rgba(139,92,246,.16));border:1px solid rgba(125,211,252,.35);min-width:0}
.smw-dest small{display:block;color:#a9c0d6;font-size:12px;text-transform:none}
.smw-dest a{display:block;margin-top:2px;color:#fff;font:650 16px ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere;text-decoration:none}
.smw-dest a:hover{text-decoration:underline}
.smw-keep{position:relative;display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:16px;color:#a9c0d6;font-size:13px}
.smw-keep span{display:inline-flex;gap:6px;align-items:center}
.smw-keep .smw-i{width:16px;height:16px;color:#34d399}
/* notices */
.smw-note{margin:16px 0 0;padding:14px 16px;border-radius:12px;border:1px solid var(--line);background:#fff}
.smw-note strong{display:flex;gap:8px;align-items:center;margin-bottom:2px}
.smw-note summary{list-style:none}.smw-note summary::-webkit-details-marker{display:none}
.smw-note.ok{border-color:#bfe6d4;background:#f2fbf6}.smw-note.bad{border-color:#f1c6c0;background:#fdf4f3}.smw-note.warn{border-color:#f2d6a9;background:#fff9ef}
.smw-note pre,.smw-log pre{margin:10px 0 0;max-height:320px;overflow:auto;padding:12px 14px;border-radius:10px;background:#0b1626;color:#d3deeb;font:12px/1.55 ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap}
.smw-spin{width:14px;height:14px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:smwspin .8s linear infinite}
\@keyframes smwspin{to{transform:rotate(360deg)}}
/* cards */
.smw-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,440px),1fr));gap:16px;margin-top:16px}
.smw-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px 22px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.smw-card h2{display:flex;gap:8px;align-items:center;margin:0 0 4px;font-size:15px}
.smw-card h2 .smw-i{color:var(--accent)}
.smw-card>p{margin:0 0 14px;color:var(--muted)}
.smw-tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.smw-tile{border:1px solid var(--line);border-radius:12px;padding:12px;min-width:0}
.smw-tile b{display:block;font-size:13px}
.smw-tile small{display:block;color:var(--muted);font:12px ui-monospace,SFMono-Regular,Menlo,monospace}
.smw-tile em{display:inline-flex;gap:6px;align-items:center;margin-top:8px;font-style:normal;font-weight:650;font-size:12.5px}
.smw-tile em::before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor}
.smw-tile.ok{background:var(--okbg);border-color:#c4ead9}.smw-tile.ok em{color:var(--ok)}
.smw-tile.fail{background:var(--badbg);border-color:#f3cbc5}.smw-tile.fail em{color:var(--bad)}
.smw-tile.na em{color:var(--muted)}
.smw-sub{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;align-items:center;margin-top:14px;color:var(--muted);font-size:13px}
.smw-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
.smw-act-form{margin:0}
.smw-act{appearance:none;width:100%;display:flex;gap:12px;align-items:center;text-align:left;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:#f8fafc;color:var(--text);font:inherit;cursor:pointer;transition:border-color .15s,background-color .15s,transform .15s}
.smw-act span{min-width:0}
.smw-act b{display:block;font-size:13.5px}
.smw-act small{display:block;color:var(--muted);font-size:12px;line-height:1.35}
.smw-act .smw-i{color:var(--accent)}
.smw-act:hover:not([disabled]){border-color:var(--accent);background:#f0f7fd}
.smw-act:active:not([disabled]){transform:translateY(1px)}
.smw-act.primary{background:linear-gradient(135deg,#0b7bc2,#4f46e5);border-color:transparent;color:#fff}
.smw-act.primary .smw-i,.smw-act.primary small{color:#dbeafe}
.smw-act.primary:hover:not([disabled]){filter:brightness(1.08);background:linear-gradient(135deg,#0b7bc2,#4f46e5)}
.smw-act.quiet .smw-i{color:var(--muted)}
.smw-act[disabled]{opacity:.55;cursor:wait}
.smw-act:focus-visible,.smw input:focus-visible,.smw-link:focus-visible,.smw-btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.smw-field{display:flex;gap:8px;flex-wrap:wrap}
.smw-field input{flex:1 1 240px;min-width:0;min-height:42px;padding:8px 12px;border:1px solid var(--line);border-radius:10px;font:13px ui-monospace,SFMono-Regular,Menlo,monospace;background:#fbfdff}
.smw-btn{appearance:none;min-height:42px;padding:8px 16px;border-radius:10px;border:1px solid var(--accent);background:var(--accent);color:#fff;font:inherit;font-weight:650;cursor:pointer}
.smw-btn:hover:not([disabled]){filter:brightness(1.08)}
.smw-btn[disabled]{opacity:.55;cursor:wait}
.smw-btn.danger{background:var(--bad);border-color:var(--bad)}
.smw-btn.ghost{background:#fff;color:var(--text);border-color:var(--line)}
.smw-ver{display:flex;gap:14px;align-items:center;margin-bottom:14px}
.smw-ver div{flex:1;border:1px solid var(--line);border-radius:12px;padding:10px 12px}
.smw-ver small{display:block;color:var(--muted);font-size:12px}
.smw-ver b{font:650 18px ui-monospace,SFMono-Regular,Menlo,monospace}
.smw-tag{display:inline-flex;align-items:center;gap:6px;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:650}
.smw-tag.ok{background:var(--okbg);color:var(--ok)}.smw-tag.warn{background:var(--warnbg);color:var(--warn)}.smw-tag.na{background:#eef2f6;color:var(--muted)}
.smw-log{margin-top:16px}
.smw-log summary{cursor:pointer;font-weight:650;display:flex;gap:8px;align-items:center}
.smw-log summary::-webkit-details-marker{display:none}
.smw-log summary::after{content:"Show";margin-left:auto;font-weight:500;color:var(--accent);font-size:13px}
.smw-log[open] summary::after{content:"Hide"}
.smw-foot{display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;margin-top:22px;padding:14px 4px 0;border-top:1px solid var(--line);color:var(--muted);font-size:13px}
.smw-foot img{width:22px;height:22px}
.smw-foot a{display:inline-flex;gap:6px;align-items:center}
.smw-foot .smw-i{width:16px;height:16px}
.smw-foot code{font-size:12px;background:#eef2f6;padding:1px 6px;border-radius:6px}
.smw-link{margin-left:auto;display:inline-flex;gap:6px;align-items:center;color:var(--muted)!important;text-decoration:none;font-size:12.5px;padding:4px 8px;border-radius:8px}
.smw-link:hover{color:var(--bad)!important;background:var(--badbg)}
/* uninstall step */
.smw-danger-hero{background:radial-gradient(700px 260px at 90% -20%,rgba(248,113,113,.28),transparent 60%),linear-gradient(135deg,#2a0f14,#3b1219)}
.smw-steps{margin:0;padding:0;list-style:none;display:grid;gap:10px}
.smw-steps li{display:flex;gap:12px;align-items:flex-start}
.smw-steps li span{flex:none;width:26px;height:26px;border-radius:50%;display:grid;place-items:center;background:#eef2f6;font-weight:700;font-size:12px}
.smw-confirm{margin-top:18px;padding:16px;border-radius:12px;border:1px dashed #e5a9a1;background:#fff8f7}
.smw-confirm label{display:block;font-weight:650;margin-bottom:8px}
.smw-confirm code{background:#fde3df;color:var(--bad);padding:1px 6px;border-radius:6px}
.smw-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:12px}
\@media (max-width:760px){.smw-flow{grid-template-columns:1fr}.smw-wire{height:36px;width:2px;margin:0 auto;background:linear-gradient(180deg,rgba(34,211,238,.15),rgba(139,92,246,.9))}.smw-wire span,.smw-wire::after{display:none}.smw-tiles{grid-template-columns:1fr}.smw-banner{display:none}}
\@media (prefers-reduced-motion:reduce){.smw *{animation:none!important;transition:none!important}}
</style>
CSS

print qq{<div class="smw $mode">};

# ---------- hero (both views share the brand)
my $hero_class = $view eq 'uninstall' ? 'smw-hero smw-danger-hero' : 'smw-hero';
print qq{<section class="$hero_class"><div class="smw-hero-top"><div class="smw-brand">} . ( $icon ? qq{<img src="$icon" alt="">} : '' )
  . qq{<div><h1>SynapseMail Webmail</h1><p>} . ( $view eq 'uninstall' ? 'Remove the plugin from this server' : 'One login for every mailbox on this server' ) . qq{</p></div></div>}
  . qq{<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap"><span class="smw-pill $mode"><span class="smw-dot"></span>} . h($mode_text) . qq{</span>}
  . ( $banner ? qq{<a href="https://synapsecyber.io" target="_blank" rel="noopener noreferrer"><img class="smw-banner" src="$banner" alt="SynapseCyber"></a>} : '' ) . qq{</div></div>};
print qq{<div class="smw-meta"><span>Plugin } . h($ver) . qq{</span><a href="$GITHUB" target="_blank" rel="noopener noreferrer">} . icon('github') . qq{github.com/xcodz/SynapseMail</a><span>} . h($mode_hint) . qq{</span></div>};

if ( $view eq 'main' ) {
    my $dest = $url ne '' ? qq{<a href="} . h($url) . qq{" target="_blank" rel="noopener noreferrer">} . h($url) . qq{</a>} : '<a>not set</a>';
    print qq{<div class="smw-flow" aria-label="What the plugin does"><div class="smw-src">}
      . qq{<div class="smw-chip">} . icon('mail') . qq{:2096<small>Webmail SSL</small></div>}
      . qq{<div class="smw-chip">} . icon('globe') . qq{webmail.&lt;domain&gt;<small>service subdomain</small></div>}
      . qq{<div class="smw-chip">} . icon('link') . qq{/webmail<small>on every site</small></div></div>}
      . qq{<div class="smw-wire" aria-hidden="true"><span></span></div>}
      . qq{<div class="smw-dest"><small>} . ( $on ? 'Opens' : 'Will open when the redirect is on' ) . qq{</small>$dest</div></div>};
    print qq{<div class="smw-keep">} . qq{<span>} . icon('shield') . qq{cPanel login :2083 unchanged</span><span>} . icon('shield') . qq{WHM login :2087 unchanged</span><span>}
      . icon('shield') . ( $status->{hook} ? 'Rebuilt after every cPanel update' : 'Not protected from cPanel updates yet' ) . qq{</span></div>};
}
print qq{</section>};

# ---------- notices
if ( $notice ne '' ) {
    print qq{<div class="smw-note warn" role="alert"><strong>Not done</strong>} . h($notice) . qq{</div>};
}
if ($busy) {
    my $gone = ( $last_label // '' ) =~ /^Uninstall/ ? ' When it finishes this page is removed: go back to WHM Home.' : ' This page refreshes by itself.';
    print qq{<div class="smw-note" role="status"><strong><span class="smw-spin" aria-hidden="true"></span>} . h( $last_label // 'Working' ) . qq{…</strong>} . h($gone)
      . ( $last_out ne '' ? '<pre>' . h($last_out) . '</pre>' : '' ) . qq{</div>};
}
elsif ( defined $last_label && $last_label ne '' && $last_rc ne '' && $view eq 'main' ) {
    my $ok   = $last_rc =~ /^0\s*$/;
    my $time = $last_time ? POSIX::strftime( '%Y-%m-%d %H:%M UTC', gmtime($last_time) ) : '';
    print qq{<details class="smw-note } . ( $ok ? 'ok' : 'bad' ) . qq{" role="status"} . ( $ok ? '' : ' open' ) . qq{><summary style="cursor:pointer"><strong style="display:inline-flex">}
      . icon( $ok ? 'check' : 'search' ) . h($last_label) . ( $ok ? ': done' : ': failed' ) . qq{</strong> <span style="color:var(--muted)">} . h($time) . qq{</span></summary>}
      . ( $last_out ne '' ? '<pre>' . h($last_out) . '</pre>' : '' ) . qq{</details>};
}

if ( $view eq 'uninstall' ) {
    # ---------- the second step: what happens, then type the word
    print qq{<div class="smw-grid"><section class="smw-card"><h2>} . icon('trash') . qq{What uninstall does</h2><p>Everything the plugin added is taken away, in this order:</p><ol class="smw-steps">}
      . qq{<li><span>1</span><div><b>Restores the original login page</b><br><small style="color:var(--muted)">Login theme goes back to "} . h( $status->{original} // 'cpanel' ) . qq{". Webmail opens Roundcube again.</small></div></li>}
      . qq{<li><span>2</span><div><b>Removes the SynapseMail login theme</b><br><small style="color:var(--muted)">/usr/local/cpanel/base/unprotected/synapsemail</small></div></li>}
      . qq{<li><span>3</span><div><b>Removes the cPanel update hook</b><br><small style="color:var(--muted)">System::upcp, post</small></div></li>}
      . qq{<li><span>4</span><div><b>Removes this WHM page and the plugin files</b><br><small style="color:var(--muted)">The command <code>smwhmplugin</code> goes too. The log stays in $LOG.</small></div></li></ol></section>};
    print qq{<section class="smw-card"><h2>} . icon('power') . qq{Just want it off?</h2><p>Turning the redirect off restores the original login page and keeps the plugin, so you can turn it on again in one click.</p>}
      . ( $on ? action_btn( 'rollback', 'Turn the redirect off', 'power', '', 'Keeps the plugin installed' ) : '<p><span class="smw-tag na">The redirect is already off</span></p>' )
      . qq{<div class="smw-confirm"><form method="post" action="$SELF?view=uninstall"><input type="hidden" name="action" value="uninstall">}
      . qq{<label for="smwConfirm">To remove the plugin, type <code>uninstall</code></label><div class="smw-field"><input id="smwConfirm" name="confirm_text" autocomplete="off" spellcheck="false" placeholder="uninstall" required pattern="[Uu][Nn][Ii][Nn][Ss][Tt][Aa][Ll][Ll]">}
      . qq{<button type="submit" class="smw-btn danger"} . ( $busy ? ' disabled' : '' ) . qq{>Uninstall</button></div></form></div>}
      . qq{<div class="smw-row"><a class="smw-btn ghost" href="$SELF" style="text-decoration:none;display:inline-flex;gap:6px;align-items:center">} . icon('back') . qq{Cancel, keep it</a></div></section></div>};
}
else {
    # ---------- health
    my @tiles = ( [ 'webmail', 'Webmail', ':2096', 'redirects' ], [ 'cpanel', 'cPanel', ':2083', 'normal login' ], [ 'whm', 'WHM', ':2087', 'normal login' ] );
    print qq{<div class="smw-grid"><section class="smw-card"><h2>} . icon('check') . qq{Login health</h2><p>What the last check saw on this server.</p><div class="smw-tiles">};
    for my $t (@tiles) {
        my $st = !$on ? 'na' : ( $detail{ $t->[0] } // 'na' );
        my $txt = $st eq 'ok' ? $t->[3] : $st eq 'fail' ? 'problem' : ( $on ? 'not checked' : ( $t->[0] eq 'webmail' ? 'Roundcube' : 'normal login' ) );
        print qq{<div class="smw-tile $st"><b>$t->[1]</b><small>$t->[2]</small><em>} . h($txt) . qq{</em></div>};
    }
    print qq{</div><div class="smw-sub"><span>Last check: } . h( when( $status->{last_check} ) || 'never' ) . qq{</span>}
      . qq{<form method="post" action="$SELF" style="margin:0"><input type="hidden" name="action" value="check"><button type="submit" class="smw-btn"} . ( $busy ? ' disabled' : '' ) . qq{>Check now</button></form></div></section>};

    # ---------- quick actions
    print qq{<section class="smw-card"><h2>} . icon('rebuild') . qq{Quick actions</h2><p>Every action runs in the background and shows its result here.</p><div class="smw-actions">}
      . ( $on ? action_btn( 'rollback', 'Turn off', 'power', 'quiet', 'Back to cPanel Webmail, settings kept' )
              : action_btn( 'enable', 'Turn on', 'power', 'primary', 'Webmail opens SynapseMail' ) )
      . action_btn( 'repair', 'Rebuild login theme', 'rebuild', '', 'From the current cPanel theme' )
      . action_btn( 'restart', 'Restart login service', 'restart', '', 'Restarts cpsrvd' )
      . action_btn( 'latest', 'Check for updates', 'search', '', 'Signed by SynapseCyber' )
      . qq{</div></section>};

    # ---------- address
    print qq{<section class="smw-card"><h2>} . icon('link') . qq{SynapseMail address</h2><p>Webmail logins open this address. Use https:// and not a Webmail port.</p>}
      . qq{<form method="post" action="$SELF" class="smw-field"><input type="hidden" name="action" value="url"><label for="smwUrl" style="position:absolute;left:-9999px">SynapseMail address</label>}
      . qq{<input type="url" id="smwUrl" name="url" required maxlength="300" placeholder="https://example.com/mail" value="} . h($url) . qq{">}
      . qq{<button type="submit" class="smw-btn"} . ( $busy ? ' disabled' : '' ) . qq{>Save</button></form></section>};

    # ---------- updates
    my $checked = $status->{latest_checked} || '';
    print qq{<section class="smw-card"><h2>} . icon('update') . qq{Updates</h2><p>Installed only when the SynapseCyber signature checks out.</p><div class="smw-ver">}
      . qq{<div><small>Installed</small><b>} . h($ver) . qq{</b></div><div><small>Latest</small><b>} . h( $latest || '?' ) . qq{</b></div></div>}
      . qq{<div class="smw-sub" style="margin-top:0"><span>}
      . ( $latest eq '' ? '<span class="smw-tag na">not checked yet</span>' : $newer ? '<span class="smw-tag warn">update available</span>' : '<span class="smw-tag ok">up to date</span>' )
      . ( $checked ne '' ? ' checked ' . h($checked) : '' ) . qq{</span>}
      . ( $newer ? qq{<form method="post" action="$SELF" style="margin:0"><input type="hidden" name="action" value="update"><button type="submit" class="smw-btn"} . ( $busy ? ' disabled' : '' ) . qq{>Update to } . h($latest) . qq{</button></form>} : '' )
      . qq{</div></section></div>};

    # ---------- log
    print qq{<details class="smw-card smw-log"><summary>} . icon('terminal') . qq{Activity log</summary><pre>} . h( $log eq '' ? '(empty)' : $log ) . qq{</pre></details>};
}

# ---------- footer
print qq{<footer class="smw-foot">} . ( $icon ? qq{<img src="$icon" alt="">} : '' )
  . qq{<span>SynapseMail by <a href="https://synapsecyber.io" target="_blank" rel="noopener noreferrer">SynapseCyber</a></span>}
  . qq{<a href="$GITHUB" target="_blank" rel="noopener noreferrer">} . icon('github') . qq{$GITHUB</a>}
  . qq{<span>Command line: <code>smwhmplugin</code></span>}
  . ( $view eq 'main' ? qq{<a class="smw-link" href="$SELF?view=uninstall">} . icon('trash') . qq{Uninstall…</a>} : '' )
  . qq{</footer>};
print qq{</div>};

if ($chrome) {
    eval { Whostmgr::HTMLInterface::deffooter(); 1 };
}
else {
    print qq{</body></html>};
}
