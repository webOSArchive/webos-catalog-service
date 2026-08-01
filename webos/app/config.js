// Our account-setup app shows ONLY the sign-in card. FirstUse.config drives the
// firstuse scene sequence; trimming it to [signin] (plus the safe-completion
// patch in FirstUse.js.patch) means: show sign-in -> write account -> close.
FirstUse.config = [
	{name: "signin", requires:{data: true}},
];
