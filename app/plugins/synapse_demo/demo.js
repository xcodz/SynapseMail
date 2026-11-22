/*!
 * SynapseMail
 * Copyright (c) 2026 SynapseCyber.io. All rights reserved.
 * Developed by Milad Ahmadipour (https://milad.io).
 *
 * Licensed under the Creative Commons Attribution-NonCommercial-NoDerivatives 4.0
 * International License (CC BY-NC-ND 4.0). You may use and share it free of charge
 * with attribution. You may not modify it, distribute modified versions, or sell it.
 * https://creativecommons.org/licenses/by-nc-nd/4.0/
 */

/* Demo login: fill in the demo account so visitors only press Sign in. */
document.addEventListener('DOMContentLoaded', function () {
  var u = document.getElementById('rcmloginuser'), p = document.getElementById('rcmloginpwd');
  if (u && !u.value) u.value = 'demo';
  if (p && !p.value) p.value = 'demo';
});
