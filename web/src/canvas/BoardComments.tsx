'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/api';
import { Bot, CheckCircle2, ClipboardCopy, Flag, History, Hourglass, MapPin, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';

import type { DesignStudioBoardView, DesignStudioComment } from '../api/types';
import { useDesignStudio } from '../provider/DesignStudioProvider';
import { cx } from '../utils/cx';

/** A pin position on a board in board pixels. */
export interface BoardPin {
  x: number;
  y: number;
}

/** Thread roots of a board with their replies, oldest first. */
export interface CommentThread {
  root: DesignStudioComment;
  replies: DesignStudioComment[];
  /** 1-based number of a pinned root (shown on the board), null for a board-level comment. */
  pinNumber: number | null;
}

/** Groups a board's comments into threads by `parentDesignStudioCommentId` (null = a thread root). */
export const buildCommentThreads = (comments: DesignStudioComment[]): CommentThread[] => {
  const repliesByRootId = new Map<number, DesignStudioComment[]>();
  for (const comment of comments) {
    if (comment.parentDesignStudioCommentId != null) {
      const replies = repliesByRootId.get(comment.parentDesignStudioCommentId) ?? [];
      replies.push(comment);
      repliesByRootId.set(comment.parentDesignStudioCommentId, replies);
    }
  }
  let pinNumber = 0;
  return comments
    .filter(comment => comment.parentDesignStudioCommentId == null)
    .map(root => ({
      root,
      replies: repliesByRootId.get(Number(root.id)) ?? [],
      pinNumber: root.x != null && root.y != null ? ++pinNumber : null,
    }));
};

const errorMessage = (error: unknown) => normalizeDddError(error, 'Request failed').message;

interface CommentLineProps {
  comment: DesignStudioComment;
  isOwn: boolean;
  writesAllowed: boolean;
}

const CommentLine = ({ comment, isOwn, writesAllowed }: CommentLineProps) => {
  const { api, components, formatDateTime, notify } = useDesignStudio();
  const { Button } = components;
  const [deleteComment, { isLoading: isDeleting }] = api.useDeleteCommentMutation();
  return (
    <div className="space-y-1">
      <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
        {comment.authorKind === 'AGENT' && <Bot className="size-3.5 text-brand-emphasis" aria-label="Agent" />}
        <span className="font-semibold text-foreground">{comment.authorName ?? 'Unknown'}</span>
        <span>· {formatDateTime(comment.changeHistory?.createdTime)}</span>
        {isOwn && (
          <Button
            variant="ghost"
            size="icon-sm"
            className="ml-auto size-6"
            aria-label="Delete comment"
            disabled={!writesAllowed || isDeleting}
            onClick={() =>
              deleteComment({ designStudioCommentId: Number(comment.id) })
                .unwrap()
                .catch(error => notify.error(errorMessage(error)))
            }
          >
            <Trash2 className="size-3.5" />
          </Button>
        )}
      </p>
      <p className="text-sm whitespace-pre-wrap break-words">{comment.text}</p>
    </div>
  );
};

interface ThreadCardProps {
  thread: CommentThread;
  /** "Copy for agent": the board reference with this thread (pin + texts). */
  onCopyReference: (thread: CommentThread) => void;
  isHighlighted: boolean;
  onHighlight: () => void;
  authAccountId: number | null;
  writesAllowed: boolean;
  /** The board file is another version than the one the thread was written on. */
  hasBoardChangedSince: boolean;
  /** The thread is deleted soon unless something happens in it ("Keep" postpones that). */
  isExpiring: boolean;
}

const ThreadCard = ({
  thread,
  onCopyReference,
  isHighlighted,
  onHighlight,
  authAccountId,
  writesAllowed,
  hasBoardChangedSince,
  isExpiring,
}: ThreadCardProps) => {
  const { root, replies, pinNumber } = thread;
  const [replyText, setReplyText] = useState('');
  const { api, components, notify } = useDesignStudio();
  const { Badge, Button, Textarea } = components;
  const [createReply, { isLoading: isReplying }] = api.useReplyToCommentMutation();
  const [resolveThread, { isLoading: isResolving }] = api.useResolveCommentMutation();
  const [reopenThread, { isLoading: isReopening }] = api.useReopenCommentMutation();
  const [patchComment, { isLoading: isPatching }] = api.usePatchCommentMutation();
  const [keepThread, { isLoading: isKeeping }] = api.useKeepCommentMutation();
  const designStudioCommentId = Number(root.id);

  const sendReply = async () => {
    try {
      await createReply({
        designStudioCommentId,
        body: { text: replyText },
      }).unwrap();
      setReplyText('');
    } catch (error) {
      notify.error(errorMessage(error));
    }
  };

  return (
    // biome-ignore lint/a11y/useSemanticElements: an article that highlights its pin on hover / focus, not a control
    <article
      className={cx(
        'space-y-3 rounded-2xl bg-card p-4 shadow-surface-raised ring-1 ring-foreground/5',
        root.isResolved && 'opacity-70',
        isHighlighted && 'ring-2 ring-brand'
      )}
      onMouseEnter={onHighlight}
      onFocus={onHighlight}
      role="group"
      aria-label={`Comment thread${pinNumber ? ` ${pinNumber}` : ''}`}
    >
      <div className="flex flex-wrap items-center gap-1.5">
        {pinNumber !== null ? (
          <Badge className="bg-primary text-primary-foreground">
            <MapPin aria-hidden /> {pinNumber}
          </Badge>
        ) : (
          <Badge variant="outline">Board</Badge>
        )}
        {root.isDecision && (
          <Badge variant="outline" className="border-transparent bg-success/15 text-success">
            <Flag aria-hidden /> Decision
          </Badge>
        )}
        {root.isResolved && (
          <Badge variant="outline" className="border-transparent bg-tint text-muted-foreground">
            Resolved{root.resolvedByName ? ` by ${root.resolvedByName}` : ''}
          </Badge>
        )}
        {hasBoardChangedSince && (
          <Badge
            variant="outline"
            className="border-transparent bg-warning/15 text-warning"
            title="The board file is another version than the one this thread was written on"
          >
            <History aria-hidden /> Board changed since
          </Badge>
        )}
        {isExpiring && (
          <Badge
            variant="outline"
            className="border-transparent bg-tint text-muted-foreground"
            title="Idle threads are deleted; “Keep” or any reply postpones that"
          >
            <Hourglass aria-hidden /> Expires soon
          </Badge>
        )}
      </div>
      <CommentLine comment={root} isOwn={authAccountId !== null && root.authorAccountId === authAccountId} writesAllowed={writesAllowed} />
      {replies.map(reply => (
        <div key={String(reply.id)} className="border-l-2 border-border pl-3">
          <CommentLine
            comment={reply}
            isOwn={authAccountId !== null && reply.authorAccountId === authAccountId}
            writesAllowed={writesAllowed}
          />
        </div>
      ))}
      <Textarea
        value={replyText}
        onChange={event => setReplyText(event.target.value)}
        placeholder="Reply…"
        rows={2}
        aria-label="Reply"
        disabled={!writesAllowed}
      />
      <div className="flex flex-wrap items-center gap-2">
        <Button size="sm" variant="outline" disabled={!writesAllowed || !replyText.trim() || isReplying} onClick={sendReply}>
          Reply
        </Button>
        <Button
          size="sm"
          variant="outline"
          disabled={!writesAllowed || isPatching}
          aria-pressed={Boolean(root.isDecision)}
          onClick={() =>
            patchComment({
              designStudioCommentId,
              body: { isDecision: !root.isDecision },
            })
              .unwrap()
              .catch(error => notify.error(errorMessage(error)))
          }
        >
          <Flag /> {root.isDecision ? 'Not a decision' : 'Decision'}
        </Button>
        <Button size="sm" variant="outline" onClick={() => onCopyReference(thread)} aria-label="Copy for agent">
          <ClipboardCopy /> Copy for agent
        </Button>
        {isExpiring && (
          <Button
            size="sm"
            variant="outline"
            disabled={!writesAllowed || isKeeping}
            onClick={() =>
              keepThread({ designStudioCommentId })
                .unwrap()
                .catch(error => notify.error(errorMessage(error)))
            }
          >
            Keep
          </Button>
        )}
        {root.isResolved ? (
          <Button
            size="sm"
            variant="outline"
            className="ml-auto"
            disabled={!writesAllowed || isReopening}
            onClick={() =>
              reopenThread({ designStudioCommentId })
                .unwrap()
                .catch(error => notify.error(errorMessage(error)))
            }
          >
            <RotateCcw /> Reopen
          </Button>
        ) : (
          <Button
            size="sm"
            variant="outline"
            className="ml-auto"
            disabled={!writesAllowed || isResolving}
            onClick={async () => {
              try {
                await resolveThread({
                  designStudioCommentId,
                  body: { replyText: replyText.trim() ? replyText : null },
                }).unwrap();
                setReplyText('');
              } catch (error) {
                notify.error(errorMessage(error));
              }
            }}
          >
            <CheckCircle2 /> Resolve
          </Button>
        )}
      </div>
    </article>
  );
};

export interface BoardCommentsProps {
  projectKey: string;
  canvasKey: string;
  board: DesignStudioBoardView;
  threads: CommentThread[];
  /** A pin placed on the board in focus view, waiting for its comment. */
  pendingPin: BoardPin | null;
  onClearPendingPin: () => void;
  highlightedCommentId: number | null;
  onHighlightComment: (designStudioCommentId: number | null) => void;
  writesAllowed: boolean;
  /** Show resolved threads too. */
  showResolved: boolean;
  onShowResolvedChange: (showResolved: boolean) => void;
  /** How to pin a comment from where the panel is shown (placeholder of the new-comment field). */
  pinHint: string;
  /** "Copy for agent" on a thread. */
  onCopyThreadReference: (thread: CommentThread) => void;
}

/** Comments of one board: new thread (on the board or at a placed pin), threads with replies, resolve, decision. */
export const BoardComments = ({
  projectKey,
  canvasKey,
  board,
  threads,
  pendingPin,
  onClearPendingPin,
  highlightedCommentId,
  onHighlightComment,
  writesAllowed,
  showResolved,
  onShowResolvedChange,
  pinHint,
  onCopyThreadReference,
}: BoardCommentsProps) => {
  const [text, setText] = useState('');
  const [isDecision, setIsDecision] = useState(false);
  const { api, components, notify, useCurrentAccountId } = useDesignStudio();
  const { Button, Checkbox, Label, Textarea } = components;
  const [createComment, { isLoading }] = api.useCreateCommentMutation();
  const authAccountId = useCurrentAccountId();
  // threads that are deleted soon unless something happens in them (all canvases; one cached request)
  const { data: expiringData } = api.useExpiringCommentsQuery();
  const expiringCommentIds = new Set(
    (expiringData?.designStudioComments.elements ?? []).map(comment => Number(comment.id))
  );
  const visibleThreads = showResolved ? threads : threads.filter(thread => !thread.root.isResolved);
  const resolvedCount = threads.length - threads.filter(thread => !thread.root.isResolved).length;

  const addComment = async () => {
    try {
      await createComment({
        projectKey,
        canvasKey,
        boardKey: board.boardKey ?? '',
        body: {
          text,
          x: pendingPin?.x ?? null,
          y: pendingPin?.y ?? null,
          isDecision,
        },
      }).unwrap();
      setText('');
      setIsDecision(false);
      onClearPendingPin();
    } catch (error) {
      notify.error(errorMessage(error));
    }
  };

  return (
    <div className="space-y-4">
      <div className="space-y-2">
        <Label htmlFor="design-comment-text" className="flex items-center gap-1.5">
          {pendingPin ? (
            <>
              <MapPin className="size-4 text-brand-emphasis" aria-hidden /> Comment at the placed pin
            </>
          ) : (
            'New comment on the board'
          )}
        </Label>
        <Textarea
          id="design-comment-text"
          value={text}
          onChange={event => setText(event.target.value)}
          placeholder={pendingPin ? 'What should change here?' : `Comment… (${pinHint})`}
          rows={3}
          disabled={!writesAllowed}
        />
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-2">
            <Checkbox
              id="design-comment-decision"
              checked={isDecision}
              onCheckedChange={checked => setIsDecision(checked === true)}
              disabled={!writesAllowed}
            />
            <Label htmlFor="design-comment-decision" className="font-normal">
              Decision
            </Label>
          </div>
          {pendingPin && (
            <Button size="sm" variant="ghost" onClick={onClearPendingPin}>
              Remove pin
            </Button>
          )}
          <Button size="sm" className="ml-auto" disabled={!writesAllowed || !text.trim() || isLoading || !board.boardKey} onClick={addComment}>
            Comment
          </Button>
        </div>
      </div>
      {resolvedCount > 0 && (
        <div className="flex items-center gap-2">
          <Checkbox
            id="design-comment-show-resolved"
            checked={showResolved}
            onCheckedChange={checked => onShowResolvedChange(checked === true)}
          />
          <Label htmlFor="design-comment-show-resolved" className="font-normal text-muted-foreground">
            Show {resolvedCount} resolved
          </Label>
        </div>
      )}
      {visibleThreads.length === 0 && <p className="text-sm text-muted-foreground">No open comments on this board.</p>}
      {visibleThreads.map(thread => (
        <ThreadCard
          key={String(thread.root.id)}
          thread={thread}
          onCopyReference={onCopyThreadReference}
          isHighlighted={highlightedCommentId === Number(thread.root.id)}
          onHighlight={() => onHighlightComment(Number(thread.root.id))}
          authAccountId={authAccountId}
          writesAllowed={writesAllowed}
          hasBoardChangedSince={
            Boolean(thread.root.contentHash && board.contentHash) && thread.root.contentHash !== board.contentHash
          }
          isExpiring={expiringCommentIds.has(Number(thread.root.id))}
        />
      ))}
    </div>
  );
};
